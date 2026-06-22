<?php

declare(strict_types=1);

namespace App\Service\Profiler;

use Symfony\Component\Finder\Finder;

/**
 * Static analyzer for the target Symfony app's source code.
 *
 * Lives in xtrace-explorer (NOT in the target app), reads the target
 * app's PHP files via SOURCE_HOST_DIR, and extracts Doctrine entity
 * metadata so we can produce PRECISE fix snippets for lazy loads —
 * no more "fill in <relationName>" hand-waving, just the real
 * property name, line, and join column.
 *
 * Why this is reliable (vs the previous hand-wavy suggestion):
 *   - We grep the actual #[ORM\Table(name: 'X')] attributes in the
 *     target's src/Entity (recursively) to find which entity maps to a
 *     given table.
 *   - We walk the parent entity's properties and `#[ORM\OneToOne(
 *     targetEntity: ...)]` / `#[ORM\ManyToOne(...)]` attributes to
 *     find the EXACT property name and join column that maps to a
 *     target table.
 *   - Doctrine's attribute syntax is regular and easy to parse with
 *     regex + token_get_all — no need for a full PHP-Parser dep.
 *
 * Scope (current):
 *   - table→entity resolution (Table attribute)
 *   - property→table resolution (relation attributes + targetEntity)
 *   - getJoinColumn extraction
 *
 * Out of scope (deferred):
 *   - caller→QueryBuilder chain (where the $user variable was built).
 *     Needs a small static-analysis pass over caller files; doable
 *     but not in this iteration.
 */
final class TargetAppAnalyzer
{
    public function __construct(
        /**
         * Path INSIDE THE CONTAINER where the target app's source is
         * mounted. We deliberately use the container path (e.g.
         * `/var/www/monolith-backend`) rather than the host path
         * because the analyser runs inside the PHP container and the
         * host path doesn't exist there.
         *
         * Compose maps SOURCE_HOST_DIR → SOURCE_CONTAINER_DIR (read-only)
         * so the host and container paths refer to the same source tree.
         */
        private readonly ?string $hostDir = null,
        /** Subpath inside the target app that contains the source (e.g. 'src'). */
        private readonly string $srcSubpath = 'src',
    ) {}

    /**
     * @return array{class: string, file: string, line: int}|null
     *   Class FQCN, file path (relative to hostDir), and line of the
     *   class declaration. null when no entity maps to the table.
     */
    public function findEntityForTable(string $tableName): ?array
    {
        $files = $this->entityFiles();
        foreach ($files as $file) {
            $src = @file_get_contents($file);
            if ($src === false) continue;
            $cls = $this->extractClassName($src);
            if ($cls === null) continue;
            // 1. Try explicit #[ORM\Table(name: 'X')] (PHP-8 attributes)
            //    or @ORM\Table(name="X") (annotations).
            // Match: #[ORM\Table(name: 'X')] (PHP-8 attribute) or @ORM\Table(name="X") (annotation).
// In the PHP single-quoted string, '\\' represents one literal '\' which in regex
// is an escaped backslash — so the regex sees '\\Table' which matches a literal
// '\Table' in the source code. PHP-attribute namespace separators (Doctrine\Table)
// appear as a literal backslash in the source, hence the '\\'.
$re = '/(?:#\[ORM\\\\Table\(name:\s*[\'"]([^\'"]+)[\'"]\s*\)\]|@ORM\\\\Table\(name=["\']([^"\']+)["\']\))/';
            $declaredTable = null;
            if (preg_match($re, $src, $m)) {
                $declaredTable = $m[1] !== '' ? $m[1] : $m[2];
            }
            // 2. Fall back to Doctrine's default naming strategy —
            //    CamelCase → snake_case (UnderscoreNamingStrategy).
            //    E.g. AffiliateWithdrawalSettings → affiliate_withdrawal_settings.
            $derived = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $cls));
            $matched = $declaredTable === $tableName || $derived === $tableName;
            if ($matched) {
                // Find class line.
                $classLine = 1;
                if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+\w+/m', $src, $m, PREG_OFFSET_CAPTURE)) {
                    $classLine = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                }
                return [
                    'class' => $this->guessFqcn($file) . '\\' . $cls,
                    'short' => $cls,
                    'file' => $this->relPath($file),
                    'line' => $classLine,
                    'declaredTable' => $declaredTable,
                    'derivedTable' => $derived,
                ];
            }
        }
        return null;
    }

    /**
     * Find ALL entity properties across the entire entity tree that
     * map to $targetTable (the lazy-load target). Returns a list of
     * candidates — usually 1 (direct relation) or 2 (owning + inverse
     * side). The user picks the one reachable from their QueryBuilder
     * (e.g. if their QB already includes 'user' as alias, they want
     * the property on User entity).
     *
     * Each candidate has the property, owning class, file:line, type
     * and joinColumn — enough to build a precise `->leftJoin(...)`
     * snippet without any placeholders.
     *
     * @return list<array{
     *   property: string,
     *   owningClass: string,    // short class name, e.g. "User"
     *   owningClassFqcn: string,
     *   line: int,
     *   file: string,           // relative to hostDir
     *   type: string,           // 'OneToOne' | 'ManyToOne' | 'OneToMany' | 'ManyToMany'
     *   targetEntity: ?string,  // FQCN of the target entity (e.g. AffiliateWithdrawalSettings)
     *   joinColumn: ?string,
     *   mappedBy: ?string,
     *   inversedBy: ?string,
     * }>
     */
    public function findRelationPropertiesForTable(string $targetTable): array
    {
        $candidates = [];
        $targetEntity = $this->findEntityForTable($targetTable);
        if ($targetEntity === null) return [];
        $targetShort = $targetEntity['short'];

        foreach ($this->entityFiles() as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') continue;
            $className = $this->extractClassName($src);
            if ($className === null) continue;

            $lines = explode("\n", $src);
            $inClass = false;
            $classDepth = 0;
            $propRe = '/^\s*(?:public|protected|private)\s+(?:readonly\s+)?[?\w|\s<>]+\s+(\$\w+)\s*(?:=|;)/';
            $attrStart = -1;
            $attrs = [];
            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                $trim = trim($line);
                if (!$inClass && preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+\w+/', $line)) {
                    $inClass = true;
                    $classDepth = substr_count($line, '{') - substr_count($line, '}');
                    continue;
                }
                if ($inClass) {
                    $classDepth += substr_count($line, '{') - substr_count($line, '}');
                    if ($classDepth <= 0) break;
                }
                if (preg_match('/^#\[.*\]\s*$/', $trim) || (str_starts_with($trim, '#[') && str_ends_with($trim, ']'))) {
                    if ($attrStart < 0) $attrStart = $i;
                    $attrs[] = $trim;
                    continue;
                }
                if (preg_match($propRe, $line, $m) && $attrStart >= 0) {
                    $attrText = implode("\n", $attrs);
                    $propName = ltrim($m[1], '$');
                    $rel = $this->parseRelationAttr($attrText);
                    if ($rel === null) {
                        $attrStart = -1; $attrs = []; continue;
                    }
                    // Does this relation point to our target table?
                    // targetEntity in parseRelationAttr currently returns the
                // short class name (e.g. "AffiliateWithdrawalSettings").
                // Compare just the short name so we don't depend on having
                // a fully-resolved FQCN at parse time.
                $relShort = $rel['targetEntity']
                    ? substr($rel['targetEntity'], strrpos($rel['targetEntity'], '\\') !== false ? strrpos($rel['targetEntity'], '\\') + 1 : 0)
                    : '';
                if ($relShort === $targetShort) {
                        $candidates[] = [
                            'property' => $propName,
                            'owningClass' => $className,
                            'owningClassFqcn' => $this->guessFqcn($file) . '\\' . $className,
                            'line' => $i + 1,
                            'file' => $this->relPath($file),
                            'type' => $rel['type'],
                            'targetEntity' => $rel['targetEntity'],
                            'joinColumn' => $rel['joinColumn'],
                            'mappedBy' => $rel['mappedBy'],
                            'inversedBy' => $rel['inversedBy'],
                        ];
                    }
                    $attrStart = -1; $attrs = [];
                } elseif (!preg_match('/^#/', $trim) && trim($line) !== '') {
                    $attrStart = -1; $attrs = [];
                }
            }
        }
        return $candidates;
    }

    /** Extract the class name (short, no namespace) from a file. */
    private function extractClassName(string $src): ?string
    {
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $src, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Find a class declaration for the given short class name within the
     * entity tree and return its absolute file path.
     */
    private function findEntityFile(string $shortClass): ?string
    {
        foreach ($this->entityFiles() as $file) {
            $src = (string) @file_get_contents($file);
            if ($src === '') continue;
            if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+' . preg_quote($shortClass, '/') . '\b/m', $src)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Parse a single `#[ORM\Xxx(...)]` attribute blob (may span
     * multiple lines, joined by \n already) and return relation metadata,
     * or null if not a relation attribute.
     *
     * @return array{type:string,targetEntity:?string,joinColumn:?string,mappedBy:?string,inversedBy:?string}|null
     */
    private function parseRelationAttr(string $attrText): ?array
    {
        // Flatten multiple #[ORM\X(...)] blocks on consecutive lines.
        $flat = preg_replace('/\s+/', ' ', $attrText);
        // What ORM\X relation type are we? In PHP single-quoted string,
        // '\\\\' = literal '\\' in regex = an escaped backslash, which
        // matches the literal '\' that appears between 'ORM' and the
        // relation type in the source.
        if (!preg_match('/#\[ORM\\\\(OneToOne|ManyToOne|OneToMany|ManyToMany)\b/i', $flat, $m)) {
            return null;
        }
        $type = $m[1];
        $targetEntity = null;
        // targetEntity: X::class — X may be a FQCN containing backslashes,
        // so include '\' in the character class. The '\\\\' in PHP single
        // quotes = '\\' in regex = literal backslash.
        if (preg_match('/targetEntity:\s*([A-Za-z0-9_\\\\]+)::class/', $flat, $m)) {
            $targetEntity = str_replace('\\\\', '\\', $m[1]);
        }
        $joinColumn = null;
        if (preg_match('/JoinColumn\(name:\s*[\'"]([^\'"]+)[\'"]/', $flat, $m)) {
            $joinColumn = $m[1];
        }
        $mappedBy = null;
        if (preg_match('/mappedBy:\s*[\'"]([^\'"]+)[\'"]/', $flat, $m)) {
            $mappedBy = $m[1];
        }
        $inversedBy = null;
        if (preg_match('/inversedBy:\s*[\'"]([^\'"]+)[\'"]/', $flat, $m)) {
            $inversedBy = $m[1];
        }
        return compact('type', 'targetEntity', 'joinColumn', 'mappedBy', 'inversedBy');
    }

    /**
     * @return iterable<string> absolute paths to entity PHP files
     */
    private function entityFiles(): iterable
    {
        if ($this->hostDir === null || !is_dir($this->hostDir)) return;
        $dir = rtrim($this->hostDir, '/') . '/' . trim($this->srcSubpath, '/') . '/Entity';
        if (!is_dir($dir)) return;
        $finder = (new Finder())->files()->in($dir)->name('*.php');
        foreach ($finder as $f) {
            yield $f->getRealPath();
        }
    }

    /**
     * Best-effort FQCN reconstruction from a file path under
     * src/Entity/. We don't actually need a perfect FQCN (the target
     * app uses whatever App\* namespace); we just want the class
     * name in some cases. Return the namespace + class.
     */
    private function guessFqcn(string $file): string
    {
        $src = (string) @file_get_contents($file);
        if ($src === '') return '';
        if (preg_match('/^\s*namespace\s+([\w\\\\]+);/m', $src, $m)) {
            return $m[1];
        }
        return '';
    }

    private function relPath(string $abs): string
    {
        if ($this->hostDir === null) return $abs;
        $base = rtrim($this->hostDir, '/') . '/';
        return str_starts_with($abs, $base) ? substr($abs, strlen($base)) : $abs;
    }
}
