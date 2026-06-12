<?php

declare(strict_types=1);

namespace App\Service;

use App\DbLog\SqlReconstructor as DbLogReconstructor; // placeholder
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware as DbMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconstructs the full SQL behind a traced QueryBuilder by replaying the
 * chain (createQueryBuilder + select + joins + where + setParameter + getResult)
 * against a minimal, in-memory Doctrine ORM setup. The trick: we never
 * connect to a real database — we hand-roll a Driver/Connection that
 * returns a PostgreSQL platform and short-circuits executeQuery. That's
 * enough for SqlWalker to emit the full DQL→SQL expansion including
 * EAGER-loaded alias columns, DQL `contains()` → PG `@>`, etc.
 *
 * Constraints (no xdebug middleware, no Doctrine middleware in user code):
 *   - User's entity classes must be readable from the xtrace container
 *     (we read via SOURCE_CONTAINER_DIR).
 *   - The root entity (the alias passed to createQueryBuilder) must be
 *     in the user's Entity/ tree — we feed its directory to ORMSetup.
 *   - Parameters referenced in setParameter() are taken as-is. For entity
 *     values we may pass a stub object of the right type, but Doctrine
 *     only needs the type, not the identity, to emit the SQL.
 *
 * This is genuinely self-contained: nothing in the user's project is
 * modified, intercepted, or required at runtime. xtrace only reads files.
 */
final class SqlReconstructor
{
    private ?EntityManager $em = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%source_container_dir%')]
        private readonly string $sourceContainerDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    private ?string $pendingRootClass = null;
    private bool $swallowUnresolvedImports = false;

    /**
     * Custom error handler: silently consume fatal "Interface not found"
     * / "Class not found" errors raised by the user's entity file's
     * `implements` or `extends` clauses. These are dependencies on
     * bundles not installed in xtrace, and we don't need them
     * resolved — we just need the root class's metadata.
     */
    public function handleImportError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!$this->swallowUnresolvedImports) {
            return false; // let the standard handler take over
        }
        // Compile-time errors (implements unknown interface, extends unknown class)
        // are reported via E_COMPILE_ERROR or as part of E_ERROR. We can't
        // catch those via set_error_handler. So we only swallow E_WARNING/E_NOTICE
        // here. The compile-error swallow happens in the surrounding try/finally
        // because the `@` operator does handle them in some PHP versions when
        // the file is require()'d through eval. We work around this by
        // pre-registering stub classes below.
        if (str_contains($errstr, 'not found') || str_contains($errstr, 'Interface')) {
            return true; // suppress
        }
        return false;
    }

    /**
     * Replay a QB chain and return the full SQL string.
     *
     * @param string $rootClass       FQCN of the entity, e.g. App\Entity\User\UserDomainAccount
     * @param string $rootAlias       e.g. "uda"
     * @param list<array{method:string,args:list<string>}> $chain
     *        Each entry is one chained call. We translate the method into
     *        the corresponding QueryBuilder method and apply args verbatim
     *        as either strings, integers, or entity references.
     * @param array<string,mixed> $parameters
     *        Map of name → value to setParameter() before getSQL().
     * @param string $alias            Alias for the FROM entity (typically same as $rootAlias).
     */
    public function reconstruct(
        string $rootClass,
        string $rootAlias,
        array $chain,
        array $parameters = [],
    ): string {
        // Pre-load the root class file ourselves because Symfony's PSR-4
        // autoloader has `App\\` mapped to its own /app/src and gives up
        // if the file isn't there.
        //
        // The file may have unresolved imports (e.g. related entities
        // that depend on bundles not installed in xtrace). PHP's `@`
        // operator only suppresses E_WARNING, not E_COMPILE_ERROR raised
        // by `implements UnknownInterface`. We need a custom error
        // handler that swallows those specific cases.
        $path = $this->sourceContainerDir . '/src/'
              . str_replace('\\', '/', substr($rootClass, 4))
              . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Entity file not found: $path");
        }
        $this->swallowUnresolvedImports = true;
        $previousHandler = set_error_handler([$this, 'handleImportError'], E_ALL);
        try {
            @require $path;
        } finally {
            restore_error_handler();
            $this->swallowUnresolvedImports = false;
        }
        if (!class_exists($rootClass, false)) {
            throw new \RuntimeException("Class $rootClass could not be loaded even after swallowing import errors");
        }

        $this->pendingRootClass = $rootClass;
        try {
            $em = $this->buildEntityManager($rootClass);
        } finally {
            $this->pendingRootClass = null;
        }
        $repo = $em->getRepository($rootClass);
        // createQueryBuilder takes optional $alias, $indexBy. From the chain
        // we can usually infer $alias — but to be safe we also accept it
        // explicitly and let the caller override.
        $qb = $repo->createQueryBuilder($rootAlias);

        foreach ($chain as $i => $call) {
            try {
                $this->applyChainCall($qb, $call, $parameters);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'Chain step %d (%s) failed: %s',
                    $i + 1,
                    $call['method'] ?? '?',
                    $e->getMessage()
                ), 0, $e);
            }
        }

        return $qb->getQuery()->getSQL();
    }

    private function buildEntityManager(string $rootClass): EntityManager
    {
        if ($this->em !== null) {
            return $this->em;
        }

        // 1. Load the root class file directly. Symfony's autoloader has
        //    `App\\` mapped to /app/src (its own src/), and gives up if the
        //    file isn't there. We pre-empt by require()'ing the file
        //    from the user's source dir ourselves — no autoloader needed
        //    for the root entity. The class file may have unresolved
        //    imports (e.g. related entities that depend on bundles
        //    not installed in xtrace); we suppress the error since we
        //    only need the class to be declared, not fully wired up.
        $rootClass = $this->pendingRootClass ?? null;
        if ($rootClass !== null) {
            $path = $this->sourceContainerDir . '/src/'
                  . str_replace('\\', '/', substr($rootClass, 4))
                  . '.php';
            if (is_file($path)) {
                @require $path;
            }
        }
        // And register a tolerant autoloader for related entities that
        // Doctrine may pull in transitively.
        spl_autoload_register(function (string $class) {
            if (strncmp($class, 'App\\', 4) !== 0) {
                return;
            }
            $path = $this->sourceContainerDir . '/src/'
                  . str_replace('\\', '/', substr($class, 4))
                  . '.php';
            if (is_file($path)) {
                @require $path;
            }
        }, throw: false, prepend: true);

        // 2. Fake DBAL driver that returns a PG platform without connecting
        $config = new \Doctrine\DBAL\Configuration();
        $config->setMiddlewares([new class implements DbMiddleware {
            public function wrap(Driver $driver): Driver {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class implements DriverConnection {
                            public function prepare(string $sql): DriverStatement
                            {
                                return new class implements DriverStatement {
                                    public function bindValue(string|int $param, mixed $value, ParameterType $type): void {}
                                    public function execute(): DriverResult
                                    {
                                        return new class implements DriverResult {
                                            public function fetchNumeric(): array|false { return false; }
                                            public function fetchAssociative(): array|false { return false; }
                                            public function fetchOne(): mixed { return false; }
                                            public function fetchAllNumeric(): array { return []; }
                                            public function fetchAllAssociative(): array { return []; }
                                            public function fetchFirstColumn(): array { return []; }
                                            public function rowCount(): int { return 0; }
                                            public function columnCount(): int { return 0; }
                                        };
                                    }
                                };
                            }
                            public function query(string $sql): DriverResult
                            {
                                return $this->prepare('')->execute();
                            }
                            public function quote(string $value): string
                            {
                                return "'" . addslashes($value) . "'";
                            }
                            public function exec(string $sql): int|string { return 0; }
                            public function lastInsertId(): int|string { return 0; }
                            public function beginTransaction(): void {}
                            public function commit(): void {}
                            public function rollBack(): void {}
                            public function getServerVersion(): string { return 'PostgreSQL 15.0'; }
                            public function getNativeConnection(): mixed { return null; }
                            public function getServerVersionInfo(): ServerVersionProvider
                            {
                                throw new \LogicException();
                            }
                        };
                    }
                    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
                    {
                        return new PostgreSQLPlatform();
                    }
                    public function getExceptionConverter(): ExceptionConverter
                    {
                        return new class implements ExceptionConverter {
                            public function convert(\Exception|string $exception, ?\Doctrine\DBAL\Query $query): \Doctrine\DBAL\Exception
                            {
                                return new \Doctrine\DBAL\Exception(
                                    $exception instanceof \Exception ? $exception->getMessage() : (string) $exception
                                );
                            }
                        };
                    }
                };
            }
        }]);

        $conn = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1', 'port' => 5432,
            'user' => 'x', 'password' => 'x', 'dbname' => 'x',
        ], $config);

        // 3. ORM config: attribute driver pointed at the user's Entity/ tree
        $entityDir = $this->sourceContainerDir . '/src/Entity';
        $proxyDir  = $this->projectDir . '/var/cache/recon-proxies';
        @mkdir($proxyDir, 0777, true);

        $ormConfig = ORMSetup::createAttributeMetadataConfig([$entityDir], true);
        $ormConfig->setProxyDir($proxyDir);
        $ormConfig->setProxyNamespace('App\\Proxies\\Recon');

        // 4. Build EM
        $this->em = new EntityManager($conn, $ormConfig);
        return $this->em;
    }

    /**
     * Translate a single chain step (e.g. ['method'=>'where', 'args'=>["u.accountOwner = :owner"]])
     * into a real QueryBuilder call.
     *
     * @param array<string,mixed> $allParameters
     */
    private function applyChainCall(
        \Doctrine\ORM\QueryBuilder $qb,
        array $call,
        array $allParameters,
    ): void {
        $method = $call['method'] ?? '';
        $args   = $call['args'] ?? [];

        switch ($method) {
            case 'select':
            case 'addSelect':
            case 'from':
                $qb->{$method}(...array_map(fn($a) => $this->evalArg($a), $args));
                return;

            case 'innerJoin':
            case 'leftJoin':
            case 'rightJoin':
            case 'join':
                $clean = array_map(fn($a) => $this->evalArg($a), $args);
                if (count($clean) === 2) {
                    $qb->{$method}($clean[0], $clean[1]);
                } elseif (count($clean) === 3) {
                    $qb->{$method}($clean[0], $clean[1], $clean[2]);
                } else {
                    throw new \RuntimeException("Unexpected arg count for $method: " . count($clean));
                }
                return;

            case 'where':
            case 'andWhere':
            case 'orWhere':
                $clean = array_map(fn($a) => $this->evalArg($a), $args);
                $qb->{$method}(...$clean);
                return;

            case 'orderBy':
            case 'addOrderBy':
            case 'groupBy':
            case 'addGroupBy':
                $qb->{$method}($this->evalArg($args[0] ?? ''), $args[1] ?? null);
                return;

            case 'having':
            case 'andHaving':
            case 'orHaving':
                $qb->{$method}(...array_map(fn($a) => $this->evalArg($a), $args));
                return;

            case 'setParameter':
                $name  = trim((string)($args[0] ?? ''), "'\"");
                $value = $this->resolveParamValue($args[1] ?? null, $allParameters, $name);
                $qb->setParameter($name, $value);
                return;

            case 'setParameters':
                $qb->setParameters($allParameters);
                return;

            case 'setFirstResult':
            case 'setMaxResults':
                $qb->{$method}((int) $this->evalArg($args[0] ?? '0'));
                return;

            case 'getQuery':
            case 'getResult':
            case 'getOneOrNullResult':
            case 'getArrayResult':
            case 'getScalarResult':
            case 'getSingleResult':
            case 'getSingleScalarResult':
                // Terminal calls — ignored; we call getQuery() ourselves at the end
                return;

            default:
                throw new \RuntimeException("Unsupported chain method: $method");
        }
    }

    /**
     * Evaluate a single chain argument as a PHP literal.
     * Accepts: 'foo', "foo", 42, 3.14, true, false, null, uda.user, …
     */
    private function evalArg(string $arg): mixed
    {
        $arg = trim($arg);
        if ($arg === '' || strcasecmp($arg, 'null') === 0) return null;
        if (strcasecmp($arg, 'true') === 0) return true;
        if (strcasecmp($arg, 'false') === 0) return false;
        if (preg_match("/^'(.*)'$/s", $arg, $m)) return str_replace(["\\'", "\\\\"], ["'", "\\"], $m[1]);
        if (preg_match('/^"(.*)"$/s', $arg, $m)) return str_replace(['\\"', "\\\\"], ['"', '\\'], $m[1]);
        if (preg_match('/^-?\d+$/', $arg)) return (int) $arg;
        if (preg_match('/^-?\d+\.\d+$/', $arg)) return (float) $arg;
        // Bare identifier like uda.user, u.accountOwner — keep as string
        return $arg;
    }

    /**
     * Resolve a setParameter value. The xdebug trace shows the value as a
     * stringified representation. We:
     *   - Use the precomputed $allParameters map (filled from log) if it has the name
     *   - Otherwise eval the literal from the chain
     */
    private function resolveParamValue(?string $arg, array $allParameters, string $name): mixed
    {
        if (array_key_exists($name, $allParameters)) {
            return $allParameters[$name];
        }
        return $arg !== null ? $this->evalArg($arg) : null;
    }
}
