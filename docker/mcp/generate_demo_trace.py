#!/usr/bin/env python3
"""
generate_demo_trace.py — generates a realistic synthetic Xdebug trace file
for XTrace Explorer demos. Uses a fictional "Acme\\Shop" Symfony application.

Output: trace__demo_shop_checkout.xt  (in the configured TRACES_DIR)

Usage:
  python3 generate_demo_trace.py --out /path/to/traces/trace__demo_shop_checkout.xt
"""

import argparse
import os
import textwrap
from pathlib import Path


# ── helpers ───────────────────────────────────────────────────────────────────

_time = 0.0010
_mem  = 445640
_line = 0

def _next(dt=0.0002, dm=320):
    global _time, _mem, _line
    _time += dt
    _mem  += dm
    _line += 1
    return _time, _mem, _line

def call(depth: int, sig: str, args: str, file: str, lineno: int,
         dt=0.0002, dm=320) -> str:
    t, m, _ = _next(dt, dm)
    indent = "  " * depth
    return f"  {t:.4f}  {m:>10}  {indent}-> {sig}({args}) {file}:{lineno}\n"

def ret(depth: int, value: str, dt=0.0001, dm=-160) -> str:
    t, m, _ = _next(dt, dm)
    indent = "  " * depth
    return f"  {t:.4f}  {m:>10}  {indent}>=> {value}\n"

def dispatch_block(depth: int, event: str, listeners: list, base_file: str) -> str:
    """Generate a full TraceableEventDispatcher->dispatch block with listeners."""
    out = ""
    # TraceableEventDispatcher->dispatch — this is what TOC parser looks for
    out += call(depth, "Symfony\\Component\\HttpKernel\\EventListener\\TraceableEventDispatcher->dispatch",
                f"$event = class Symfony\\Component\\HttpKernel\\Event\\RequestEvent {{ }}, $eventName = '{event}'",
                "symfony/http-kernel/EventListener/TraceableEventDispatcher.php", 42,
                dt=0.0005, dm=640)
    out += call(depth+1, "Symfony\\Component\\EventDispatcher\\EventDispatcher->dispatch",
                f"$event = class Symfony\\Component\\HttpKernel\\Event\\RequestEvent {{ }}, $eventName = '{event}'",
                "symfony/event-dispatcher/EventDispatcher.php", 73,
                dt=0.0001, dm=128)

    for listener_class, method, calls in listeners:
        out += call(depth+2, "Symfony\\Component\\EventDispatcher\\EventDispatcher->callListeners",
                    "$listeners = [...]", "symfony/event-dispatcher/EventDispatcher.php", 188,
                    dt=0.0001, dm=96)
        out += call(depth+3, "Symfony\\Component\\HttpKernel\\EventListener\\TraceableEventDispatcher\\WrappedListener->__invoke",
                    "$event = class Symfony\\Component\\HttpKernel\\Event\\RequestEvent {{ }}",
                    "symfony/http-kernel/EventListener/TraceableEventDispatcher.php", 261,
                    dt=0.0001, dm=64)
        # Actual listener
        out += call(depth+4, f"{listener_class}->{method}",
                    "$event = class Symfony\\Component\\HttpKernel\\Event\\RequestEvent {{ }}",
                    base_file, 45, dt=0.0003, dm=512)
        for sub_sig, sub_args, sub_file, sub_line in calls:
            out += call(depth+5, sub_sig, sub_args, sub_file, sub_line, dt=0.0002, dm=384)
            out += ret(depth+5, "NULL")
        out += ret(depth+4, "NULL")

    return out


# ── trace content ─────────────────────────────────────────────────────────────

def build_trace() -> str:
    out = "TRACE START [2024-01-15 14:32:07.042819]\n"

    # Depth 0: {main}
    out += call(0, "{main}", "", "/var/www/acme-shop/public/index.php", 0, dt=0.0019, dm=4456)

    # Bootstrap
    out += call(1, "require_once", "/var/www/acme-shop/vendor/autoload.php",
                "/var/www/acme-shop/public/index.php", 5, dt=0.0003, dm=640)
    out += call(2, "Composer\\Autoload\\ClassLoader->register", "$prepend = TRUE",
                "/var/www/acme-shop/vendor/composer/autoload_real.php", 34, dt=0.0002, dm=384)
    out += call(3, "spl_autoload_register", "$callback = [...], $throw = TRUE",
                "/var/www/acme-shop/vendor/composer/ClassLoader.php", 389, dt=0.0001, dm=128)
    out += ret(3, "TRUE")
    out += ret(2, "NULL")
    out += ret(1, "NULL")

    # Request::createFromGlobals — this is where TraceParser finds $_SERVER info
    # The line must contain REQUEST_METHOD, REQUEST_URI, HTTP_HOST, HTTP_COOKIE on one line
    out += call(1, "Symfony\\Component\\HttpFoundation\\Request::createFromGlobals",
                (
                    "$_server = ['REQUEST_URI' => '/checkout/pay', 'REQUEST_METHOD' => 'POST', "
                    "'HTTP_HOST' => 'shop.acme.example', 'HTTP_COOKIE' => 'acme_token=<JWT>; acme_cart=cart_8f3bc', "
                    "'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.168.1.42', "
                    "'REQUEST_TIME_FLOAT' => 1705325527.042819, 'HTTP_USER_AGENT' => 'Mozilla/5.0']"
                ),
                "/var/www/acme-shop/vendor/symfony/http-foundation/Request.php", 330,
                dt=0.0003, dm=640)
    out += ret(1, "class Symfony\\Component\\HttpFoundation\\Request { }")

    # Kernel boot
    out += call(1, "Acme\\Shop\\Kernel->__construct", "$environment = 'prod', $debug = FALSE",
                "/var/www/acme-shop/src/Kernel.php", 18, dt=0.0004, dm=896)
    out += ret(1, "NULL")
    out += call(1, "Acme\\Shop\\Kernel->handle",
                "$request = class Symfony\\Component\\HttpFoundation\\Request { $method = 'POST', $uri = '/checkout/pay' }",
                "/var/www/acme-shop/public/index.php", 20, dt=0.0005, dm=1024)
    out += call(2, "Symfony\\Component\\HttpKernel\\HttpKernel->handle",
                "$request = class Symfony\\Component\\HttpFoundation\\Request { }",
                "/var/www/acme-shop/vendor/symfony/http-kernel/Kernel.php", 202, dt=0.0003, dm=640)
    out += call(3, "Symfony\\Component\\HttpKernel\\HttpKernel->handleRaw",
                "$request = class Symfony\\Component\\HttpFoundation\\Request { }",
                "/var/www/acme-shop/vendor/symfony/http-kernel/HttpKernel.php", 85, dt=0.0002, dm=512)

    # ── kernel.request ────────────────────────────────────────────────────────
    out += dispatch_block(4, "kernel.request", [
        ("Symfony\\Component\\HttpKernel\\EventListener\\ValidateRequestListener", "onKernelRequest", [
            ("Symfony\\Component\\HttpFoundation\\Request->isMethodSafe", "", "/var/www/acme-shop/vendor/symfony/http-foundation/Request.php", 1248),
        ]),
        ("Symfony\\Component\\HttpKernel\\EventListener\\\SessionListener", "onKernelRequest", [
            ("Symfony\\Component\\HttpFoundation\\Request->hasPreviousSession", "", "/var/www/acme-shop/vendor/symfony/http-foundation/Request.php", 688),
            ("Symfony\\Component\\HttpFoundation\\\Session\\\Session->start", "", "/var/www/acme-shop/vendor/symfony/http-foundation/Session/Session.php", 58),
        ]),
        ("Symfony\\Component\\HttpKernel\\EventListener\\RouterListener", "onKernelRequest", [
            ("Symfony\\Component\\Routing\\Matcher\\UrlMatcher->matchRequest", "$request = class Symfony\\Component\\HttpFoundation\\Request { }", "/var/www/acme-shop/vendor/symfony/routing/Matcher/UrlMatcher.php", 43),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\MaintenanceModeSubscriber", "onKernelRequest", [
            ("Acme\\\Shop\\Repository\\SettingsRepository->findByKey", "$key = 'maintenance_mode'", "/var/www/acme-shop/src/Repository/SettingsRepository.php", 34),
            ("Doctrine\\ORM\\EntityManager->createQueryBuilder", "", "/var/www/acme-shop/vendor/doctrine/orm/src/EntityManager.php", 196),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\LocaleSubscriber", "onKernelRequest", [
            ("Symfony\\Component\\HttpFoundation\\Request->getPreferredLanguage", "$locales = ['en', 'fr', 'de', 'es']", "/var/www/acme-shop/vendor/symfony/http-foundation/Request.php", 1540),
            ("Symfony\\Component\\HttpFoundation\\Request->setLocale", "$locale = 'en'", "/var/www/acme-shop/vendor/symfony/http-foundation/Request.php", 742),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\AuthTokenSubscriber", "onKernelRequest", [
            ("Acme\\\Shop\\Security\\JwtTokenValidator->validate", "$token = '<JWT>'", "/var/www/acme-shop/src/Security/JwtTokenValidator.php", 52),
            ("Acme\\\Shop\\Repository\\UserRepository->findByUuid", "$uuid = 'usr_42f9a'", "/var/www/acme-shop/src/Repository/UserRepository.php", 28),
            ("Symfony\\Component\\Security\\Core\\Authentication\\Token\\Storage\\TokenStorage->setToken",
             "$token = class Symfony\\Component\\Security\\Core\\Authentication\\Token\\UsernamePasswordToken { }",
             "/var/www/acme-shop/vendor/symfony/security-core/Authentication/Token/Storage/TokenStorage.php", 30),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\CartSubscriber", "onKernelRequest", [
            ("Acme\\\Shop\\Service\\CartService->getOrCreateCart", "$sessionId = 'sess_x9f2a'", "/var/www/acme-shop/src/Service/CartService.php", 28),
            ("Acme\\\Shop\\Repository\\CartRepository->findBySessionId", "$sessionId = 'sess_x9f2a'", "/var/www/acme-shop/src/Repository/CartRepository.php", 22),
        ]),
    ], "/var/www/acme-shop/src/EventSubscriber/AuthTokenSubscriber.php")

    # ── kernel.controller ─────────────────────────────────────────────────────
    out += dispatch_block(4, "kernel.controller", [
        ("Symfony\\Component\\HttpKernel\\EventListener\\RouterListener", "onKernelController", [
            ("Symfony\\Component\\Routing\\Router->matchRequest", "$request = class Request { }", "/var/www/acme-shop/vendor/symfony/routing/Router.php", 271),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\RateLimitingSubscriber", "onKernelController", [
            ("Symfony\\Component\\RateLimiter\\RateLimiterFactory->create", "$key = 'checkout_ip_127.0.0.1'", "/var/www/acme-shop/vendor/symfony/rate-limiter/RateLimiterFactory.php", 45),
            ("Symfony\\Component\\RateLimiter\\Policy\\TokenBucketLimiter->consume", "$tokens = 1", "/var/www/acme-shop/vendor/symfony/rate-limiter/Policy/TokenBucketLimiter.php", 62),
        ]),
    ], "/var/www/acme-shop/src/EventSubscriber/RateLimitingSubscriber.php")

    # ── controller execution ──────────────────────────────────────────────────
    out += call(4, "Acme\\\Shop\\Controller\\CheckoutController->pay",
                "$request = class Request { }, $cartId = 'cart_8f3bc'",
                "/var/www/acme-shop/src/Controller/CheckoutController.php", 78, dt=0.0010, dm=2048)
    out += call(5, "Acme\\\Shop\\\Service\\CartService->getCart",
                "$cartId = 'cart_8f3bc'",
                "/var/www/acme-shop/src/Service/CartService.php", 34, dt=0.0004, dm=768)
    out += call(6, "Acme\\\Shop\\Repository\\CartRepository->find",
                "$id = 'cart_8f3bc'",
                "/var/www/acme-shop/src/Repository/CartRepository.php", 18, dt=0.0003, dm=640)
    out += call(7, "Doctrine\\ORM\\EntityManager->find",
                "$className = 'Acme\\\Shop\\Entity\\Cart', $id = 'cart_8f3bc'",
                "/var/www/acme-shop/vendor/doctrine/orm/src/EntityManager.php", 320, dt=0.0005, dm=1024)
    out += ret(7, "class Acme\\\Shop\\Entity\\Cart { $id = 'cart_8f3bc', $total = 49.99 }")
    out += ret(6, "class Acme\\\Shop\\Entity\\Cart { }")
    out += ret(5, "class Acme\\\Shop\\Entity\\Cart { }")

    out += call(5, "Acme\\\Shop\\\Service\\PaymentService->charge",
                "$cart = class Cart { }, $method = 'stripe'",
                "/var/www/acme-shop/src/Service/PaymentService.php", 67, dt=0.0008, dm=1536)
    out += call(6, "Acme\\\Shop\\Gateway\\\StripeGateway->createCharge",
                "$amount = 4999, $currency = 'usd', $customerId = 'cus_demo'",
                "/var/www/acme-shop/src/Gateway/StripeGateway.php", 44, dt=0.0015, dm=2048)
    out += call(7, "Symfony\\Component\\HttpClient\\CurlHttpClient->request",
                "$method = 'POST', $url = 'https://api.stripe.com/v1/charges'",
                "/var/www/acme-shop/vendor/symfony/http-client/CurlHttpClient.php", 108, dt=0.0020, dm=3072)
    out += ret(7, "class Symfony\\Component\\HttpClient\\Response\\CurlResponse { }")
    out += call(7, "Acme\\\Shop\\Gateway\\\StripeGateway->parseResponse",
                "$response = class CurlResponse { }",
                "/var/www/acme-shop/src/Gateway/StripeGateway.php", 89, dt=0.0003, dm=512)
    out += ret(7, "['id' => 'ch_demo_1234', 'status' => 'succeeded']")
    out += ret(6, "class Acme\\\Shop\\DTO\\ChargeResult { $id = 'ch_demo_1234', $status = 'succeeded' }")
    out += ret(5, "class Acme\\\Shop\\DTO\\ChargeResult { }")

    out += call(5, "Acme\\\Shop\\\Service\\OrderService->createFromCart",
                "$cart = class Cart { }, $chargeId = 'ch_demo_1234'",
                "/var/www/acme-shop/src/Service/OrderService.php", 52, dt=0.0006, dm=1280)
    out += call(6, "Doctrine\\ORM\\EntityManager->persist",
                "$entity = class Acme\\\Shop\\Entity\\Order { $id = NULL }",
                "/var/www/acme-shop/vendor/doctrine/orm/src/EntityManager.php", 394, dt=0.0002, dm=384)
    out += ret(6, "NULL")
    out += call(6, "Doctrine\\ORM\\EntityManager->flush", "",
                "/var/www/acme-shop/vendor/doctrine/orm/src/EntityManager.php", 416, dt=0.0008, dm=1024)
    out += ret(6, "NULL")
    out += ret(5, "class Acme\\\Shop\\Entity\\Order { $id = 'ord_9a2f1' }")
    out += ret(4, "class Symfony\\Component\\HttpFoundation\\JsonResponse { $status = 201 }")

    # ── kernel.response ───────────────────────────────────────────────────────
    out += dispatch_block(4, "kernel.response", [
        ("Symfony\\Component\\HttpKernel\\EventListener\\ResponseListener", "onKernelResponse", [
            ("Symfony\\Component\\HttpFoundation\\Response->setCharset", "$charset = 'UTF-8'", "/var/www/acme-shop/vendor/symfony/http-foundation/Response.php", 462),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\CorsSubscriber", "onKernelResponse", [
            ("Symfony\\Component\\HttpFoundation\\Response->headers->set", "$key = 'Access-Control-Allow-Origin', $value = '*'", "/var/www/acme-shop/vendor/symfony/http-foundation/ResponseHeaderBag.php", 62),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\JwtRefreshSubscriber", "onKernelResponse", [
            ("Acme\\\Shop\\\Security\\JwtTokenGenerator->generate", "$user = class Acme\\\Shop\\Entity\\User { $id = 42 }", "/var/www/acme-shop/src/Security/JwtTokenGenerator.php", 38),
            ("Symfony\\Component\\HttpFoundation\\Cookie->create", "$name = 'acme_token', $value = '<JWT>'", "/var/www/acme-shop/vendor/symfony/http-foundation/Cookie.php", 64),
        ]),
    ], "/var/www/acme-shop/src/EventSubscriber/JwtRefreshSubscriber.php")

    # ── kernel.terminate ──────────────────────────────────────────────────────
    out += dispatch_block(4, "kernel.terminate", [
        ("Acme\\\Shop\\EventSubscriber\\OrderConfirmationSubscriber", "onTerminate", [
            ("Symfony\\Component\\Messenger\\MessageBus->dispatch",
             "$message = class Acme\\\Shop\\Message\\\SendOrderConfirmationEmail { $orderId = 'ord_9a2f1' }",
             "/var/www/acme-shop/src/EventSubscriber/OrderConfirmationSubscriber.php", 44),
        ]),
        ("Acme\\\Shop\\EventSubscriber\\MetricsSubscriber", "onTerminate", [
            ("Acme\\\Shop\\Metrics\\PrometheusCollector->increment",
             "$metric = 'checkout_completed_total', $labels = ['method' => 'stripe']",
             "/var/www/acme-shop/src/Metrics/PrometheusCollector.php", 28),
        ]),
    ], "/var/www/acme-shop/src/EventSubscriber/OrderConfirmationSubscriber.php")

    out += ret(3, "class Symfony\\Component\\HttpFoundation\\JsonResponse { }")
    out += ret(2, "class Symfony\\Component\\HttpFoundation\\JsonResponse { }")
    out += ret(1, "class Symfony\\Component\\HttpFoundation\\JsonResponse { }")
    out += ret(0, "NULL")
    out += "\nTRACE END   [2024-01-15 14:32:07.129344]\n"
    return out


# ── main ──────────────────────────────────────────────────────────────────────

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--out", required=True, help="Output path for the .xt file")
    args = p.parse_args()

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    content = build_trace()
    out_path.write_text(content)
    print(f"Generated demo trace: {out_path} ({len(content)} bytes, {content.count(chr(10))} lines)")


if __name__ == "__main__":
    main()
