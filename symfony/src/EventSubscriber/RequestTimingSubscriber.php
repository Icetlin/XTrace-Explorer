<?php

namespace App\EventSubscriber;

use App\Entity\EndpointTiming;
use App\Repository\TraceFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class RequestTimingSubscriber implements EventSubscriberInterface
{
    private const TIMING_ATTRIBUTE = '_timing_start';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TraceFileRepository $traceFileRepo,
        private readonly RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => 'onRequest',
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) return;

        $request->attributes->set(self::TIMING_ATTRIBUTE, microtime(true));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $start = $request->attributes->get(self::TIMING_ATTRIBUTE);
        if ($start === null) return;

        $path = $request->getPathInfo();
        // Don't let the timings endpoint pollute its own data.
        if (str_starts_with($path, '/api/timings')) return;

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $timing = new EndpointTiming();
        $timing->setEndpointUrl($this->resolveRoutePath($request))
            ->setEndpointMethod($request->getMethod())
            ->setTraceName($this->resolveTraceName($request))
            ->setDurationMs($durationMs);

        $this->em->persist($timing);
        $this->em->flush();
    }

    private function resolveRoutePath(Request $request): string
    {
        $routeName = $request->attributes->get('_route');
        $route = $routeName ? $this->router->getRouteCollection()->get($routeName) : null;

        return $route?->getPath() ?? $request->getPathInfo();
    }

    private function resolveTraceName(Request $request): ?string
    {
        $fileId = $request->attributes->get('id');
        if ($fileId === null || !ctype_digit((string) $fileId)) return null;

        return $this->traceFileRepo->find((int) $fileId)?->getOriginalName();
    }
}
