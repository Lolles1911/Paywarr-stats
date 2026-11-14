<?php

/*
 * The small parts of Paymenter v1.5.9 and Laravel 13.40 that Paywarr Stats
 * uses, so the extension can run without them. Each stand-in keeps the
 * signature and behaviour of the real method it replaces.
 */

namespace App\Attributes {
    #[\Attribute]
    class ExtensionMeta
    {
        public function __construct(
            public string $name,
            public string $description,
            public string $version,
            public string $author,
            public string $url = '',
            public string $icon = '',
        ) {}
    }
}

namespace App\Classes\Extension {
    class Extension
    {
        public function __construct(public $config = []) {}

        public function config($key)
        {
            return $this->config[$key] ?? null;
        }
    }
}

namespace App\Models {
    /** Only named in PaywarrStats::page(), which needs a real Paymenter. */
    class Service {}
}

namespace Illuminate\Foundation {
    class FakeApplication
    {
        public static ?FakeApplication $instance = null;

        public string $locale = 'en';

        public function getLocale(): string
        {
            return $this->locale;
        }

        public function setLocale($locale): void
        {
            $this->locale = $locale;
        }
    }
}

namespace {
    function app($abstract = null)
    {
        return \Illuminate\Foundation\FakeApplication::$instance ??= new \Illuminate\Foundation\FakeApplication;
    }
}

namespace Illuminate\Support {
    class HtmlString
    {
        public function __construct(public string $html = '') {}
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $warnings = [];

        public static function warning(string $message): void
        {
            self::$warnings[] = $message;
        }
    }

    class Auth
    {
        public static $user = null;

        public static function check(): bool
        {
            return self::$user !== null;
        }

        public static function id()
        {
            return self::$user?->id;
        }

        public static function user()
        {
            return self::$user;
        }
    }

    class Cache
    {
        public static array $store = [];

        public static function remember($key, $ttl, \Closure $callback)
        {
            return self::$store[$key] ??= $callback();
        }
    }

    class Event
    {
        public static array $listeners = [];

        public static function listen($events, $listener = null): void
        {
            self::$listeners[] = [$events, $listener];
        }
    }

    class Route
    {
        public static array $routes = [];

        public static function middleware($middleware): RouteRegistrar
        {
            return (new RouteRegistrar)->middleware($middleware);
        }
    }

    class RouteRegistrar
    {
        public array $middleware = [];

        public function middleware($middleware): static
        {
            $this->middleware = (array) $middleware;

            return $this;
        }

        public function get($uri, $action): RegisteredRoute
        {
            // Laravel stores route URIs without leading or trailing slashes.
            return Route::$routes[] = new RegisteredRoute('GET', trim($uri, '/'), $action, $this->middleware);
        }
    }

    class RegisteredRoute
    {
        public string $name = '';

        /** @var array<string, string> parameter => pattern, as Route::where*() set them */
        public array $wheres = [];

        public function __construct(public string $method, public string $uri, public $action, public array $middleware) {}

        public function name($name): static
        {
            $this->name .= $name;

            return $this;
        }

        public function whereNumber($parameter): static
        {
            $this->wheres[$parameter] = '[0-9]+';

            return $this;
        }
    }

    /** Http::withHeaders()->timeout()->get(), as Laravel's client does it, over real HTTP. */
    class Http
    {
        public static function withHeaders(array $headers): PendingRequest
        {
            return new PendingRequest($headers);
        }
    }

    class PendingRequest
    {
        private int $timeout = 30;

        public function __construct(private array $headers) {}

        public function timeout(int $seconds): static
        {
            $this->timeout = $seconds;

            return $this;
        }

        public function get(string $url): Response
        {
            $headers = [];
            foreach ($this->headers as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($curl);
            if ($body === false) {
                $error = curl_error($curl);
                curl_close($curl);

                // Laravel throws Illuminate\Http\Client\ConnectionException here.
                throw new \RuntimeException('cURL error: ' . $error);
            }
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            curl_close($curl);

            return new Response($status, $body, ['content-type' => $type]);
        }
    }

    class Response
    {
        public function __construct(private int $status, private string $body, private array $headers = []) {}

        public function status(): int
        {
            return $this->status;
        }

        public function body(): string
        {
            return $this->body;
        }

        public function json()
        {
            return json_decode($this->body, true);
        }

        /** As Illuminate\Http\Client\Response::header(): one header's value, "" when absent. */
        public function header(string $name): string
        {
            return (string) ($this->headers[strtolower($name)] ?? '');
        }
    }
}
