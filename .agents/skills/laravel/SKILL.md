---
name: laravel
description: "Laravel coding standards and architecture patterns: lightweight DDD, layer responsibilities, dependency injection, and PHP best practices. Use when writing or reviewing PHP code in a Laravel project, creating new classes/services, or when asked about project structure or code conventions."
---

# Laravel Coding Standards

## Architecture: Domain-Driven Design (Light)

Business logic is organized into **domains** under `App\Services\`, not by technical layer. Laravel-native classes (Controllers, Models, FormRequests, API Resources) stay in their conventional `app/` locations. Events and Listeners are domain concerns and live inside the domain under `App\Services\{Domain}\Events\` and `App\Services\{Domain}\Listeners\`.

### Domain Structure

```
app/Services/
└── Ai/                         ← Domain (singular noun, CamelCase)
    ├── Clients/                 ← Structural namespace (group of related classes)
    ├── Contracts/              ← Interfaces (prefer over Interfaces/)
    ├── Events/                 ← Domain events (always at domain root)
    │   └── Members/            ← optional sub-namespace for grouping
    ├── Repositories/           ← DB access
    │   └── Queries/            ← Complex/reused query objects
    ├── Exceptions/             ← Domain exceptions
    ├── Values/                 ← Value objects, DTOs, enums
    ├── AiFactory.php           ← Named collaborator (direct partner of service)
    └── AiService.php           ← Domain service (@api)
```

Laravel-native classes mirror domain structure via subfolders:

```
app/Http/Controllers/Ai/
app/Http/Requests/Ai/
app/Http/Resources/Ai/
app/Models/Ai/
```

### Naming Rules

- **Domain namespaces**: singular noun (`Auth`, `Storage`, `Ai`)
- **Structural namespaces**: plural for countable nouns (`Exceptions`, `Values`, `Contracts`, `Repositories`), singular for mass/uncountable (`Middleware`)
- **Acronyms in namespace segments**: CamelCase — `Ai` not `AI`, `Mcp` not `MCP`, `Http` not `HTTP`
- Prefer `Contracts/` over `Interfaces/`
- **`Utils/` is always a classification failure** — find a more precise namespace

### What Goes Where

Move a class into a structural namespace when it **fits a structural archetype** (`Exceptions/`, `Values/`, `Contracts/`, `Repositories/`). When multiple classes collaborate around a shared concept, extract them into a named structural namespace (e.g. `Clients/` for a group of `ClientInterface` decorators).

When internal complexity grows:

1. Can it be its own domain? Always preferred — extract it.
2. Can the logic merge into the main domain service? Do that.
3. Neither? Create a structural namespace.

---

## Layer Responsibilities

### Controllers

Handle HTTP only. No business logic.

```php
// Good — thin controller
public function store(CreateMessageRequest $request, MessageService $service): MessageResource
{
    return new MessageResource($service->createMessage($request->validated()));
}
```

- Delegate validation to `FormRequest`
- Call one service method
- Return `ApiResource` (JSON) or redirect
- No direct DB access, no conditional logic beyond routing

### FormRequests

All validation and authorization lives here. Never validate in controllers.

### API Resources

Transform models into JSON. Live in `App\Http\Resources\{Domain}\`. Since Laravel instantiates Resources outside the container, constructor injection is unavailable — use a service locator helper when a service is needed (see DI section).

### Services

The `@api` public surface of a domain. Rules:

- **Stateless** — no mutable instance variables
- **Singletons** — register with `#[Singleton]`
- **Lightweight constructor** — no heavy initialization; defer to factories or first call
- Always live at the **domain root**, never inside a structural namespace
- All dependencies injected via constructor

**Sub-services over traits**: Split large services into sub-services exposed as `public readonly` properties — never use traits as a file-splitting mechanism. Traits hide coupling and invisible dependencies.

```php
// Good
class RoomService
{
    public function __construct(
        public readonly RoomMemberService $members,
        public readonly RoomMessageService $messages,
        private readonly RoomRepository $repository,
    ) {}
}
// Callers: $roomService->members->add($slug, $data);
```

**Aggregating services**: When a domain has multiple sub-services, expose via a single aggregating service so callers have one injection point.

### Repositories

All DB access goes here. **Never call Eloquent model statics from services or controllers** — models can't be injected, so they can't be mocked.

```php
readonly class AiModelRepository
{
    public function findActiveByProvider(string $providerId): Collection
    {
        return AiModel::where('provider_id', $providerId)->where('active', true)->get();
    }
}
```

For complex/reused queries, extract into a `Query` object in `Repositories/Queries/`. Each Query object owns exactly one SQL query. For simple one-off lookups, inline Eloquent in the repository.

Eloquent query scopes belong in Repository classes or Query objects, not in models.

### Models

**Data descriptors only.** Define structure, relationships, and casts. Do not perform work.

Allowed:

- Eloquent relationships
- Attribute casts and accessors
- Simple helpers operating only on instance data

Forbidden:

- Business logic or workflows
- Query scopes, global scopes
- Cache access, external service calls, facade usage
- Static/global state
- Service locator pattern (no `app()` or equivalent inside models)

### Value Objects & DTOs

Live in `{Domain}/Values/`. Always `readonly`. Use static factory methods (`from...`, `tryFrom...`). No external dependencies.

```php
readonly class StoredFileIdentifier
{
    private function __construct(
        public string $uuid,
        public StoredFileCategory $category,
        public string $extension,
    ) {}

    public static function fromCategoryAndUuid(
        StoredFileCategory $category, string $uuid, string $extension,
    ): self {
        return new self($uuid, $category, $extension);
    }
}
```

### Enums

Use enums for all constrained value sets. Live in `{Domain}/Values/` alongside value objects.

### Exceptions

Live in `{Domain}/Exceptions/`. Every domain defines a marker interface `{Domain}ExceptionInterface extends \Throwable`.

Rules:

- Never throw built-in PHP exceptions directly — always create a dedicated class
- Expose **static factory methods** with full contextual messages; never construct with `new` from outside
- Messages must be speaking and helpful: what was tried, what failed, how to fix it
- Catch `\Throwable`, not `\Exception`

**Logging at catch sites:**

- **Swallow** (return null/false): log here with `['exception' => $e]` — no one else will
- **Re-throw/convert**: log only contextual enrichment you're adding; don't double-log
- Use `['exception' => $e]` in PSR log context — `exception` is a reserved key that triggers nice formatting in most loggers

```php
// Swallow + log
try {
    return $this->storage->retrieve($id);
} catch (\Throwable $e) {
    $this->logger->error('Failed to retrieve file ' . $id, ['exception' => $e]);
    return null;
}
```

### Events & Listeners

**Events** live in `App\Services\{Domain}\Events\`. Always at the domain root; may be grouped into sub-namespaces.

**Listeners** live in `App\Services\{Domain}\Listeners\`. Named as actions (`NotifyRoomMembers`, `LogMessageActivity`). Register them in your service provider or via Laravel's auto-discovery configuration.

**Event naming** — pick one tense:

- Past: `MessageSentEvent`, `RoomCreatedEvent`
- Progressive: `AiWritingStartingEvent`
- Before: `BeforeCreatingRoomEvent`

Always add `Event` suffix.

**Event classes**: `readonly`, use `Dispatchable` trait, strongly-typed `public readonly` constructor args. No raw `array` payloads — use typed value objects.

```php
readonly class RoomCreatedEvent
{
    use Dispatchable;
    public function __construct(public Room $room) {}
}
```

**Filter events** — mutable synchronous hooks that let listeners influence core logic. Suffix: `...FilterEvent`.

- Use a custom dispatch trait that returns the event instance from `dispatch()` (instead of the standard `Dispatchable` which returns void)
- Never `ShouldBroadcast` or `ShouldQueue` — filter events are always synchronous
- Private properties with getters/setters; read-only context gets getter only
- Do not declare the class `readonly`

```php
// The calling service reads mutated state immediately after dispatch
$isAllowed = ModelPermissionFilterEvent::dispatch($user, $model)->isAllowed();
```

**Broadcasting events**: Implement `ShouldBroadcast`, define `broadcastOn()` (prefer `PrivateChannel`), define explicit `broadcastWith(): array`. Add `SerializesModels` when the event carries Eloquent models and may be queued. Add `InteractsWithSockets` only when you need to exclude the triggering socket.

### Contracts (Interfaces)

Use interfaces where you expect multiple or replaceable implementations. Do not introduce interfaces speculatively — if there's only one implementation and no plan to replace it, use a plain class.

---

## Code Standards

### Required in Every PHP File

```php
<?php
declare(strict_types=1);
namespace App\Services\Ai;
```

### PHP Native Classes — Always Fully Qualified

```php
// Good
} catch (\Throwable $e) { ... }
class MyException extends \RuntimeException {}

// Bad — never import native classes via `use`
use \Throwable;
} catch (Throwable $e) { ... }
```

Reason: many packages define classes with identical names; IDE auto-import can silently pull in the wrong one.

### Type Declarations

Always declare parameter and return types. Avoid `mixed`. Use DocBlock for complex array shapes:

```php
/** @return Collection<int, AiModel> */
public function getActiveModels(): Collection { ... }
```

### Dependency Injection

Always inject via constructor. Never use facades or `app()` helpers in services, repositories, or value objects.

Use Laravel contextual attributes:

```php
#[Singleton]
readonly class OrderService
{
    public function __construct(
        private OrderRepository $repository,
        #[Config('shop.currency')] private string $currency,
        #[Cache] private CacheRepository $cache,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {}
}
```

| Need         | How                                                     |
|--------------|---------------------------------------------------------|
| Config value | `#[Config('app.key')] string $value`                    |
| Cache        | `#[Cache] Illuminate\Contracts\Cache\Repository $cache` |
| Logging      | `Psr\Log\LoggerInterface $logger`                       |
| Filesystem   | `Illuminate\Contracts\Filesystem\Filesystem $fs`        |
| Singleton    | `#[Singleton]` on the class                             |
| DB access    | Create a `Repository` class — never inject `DB` facade  |

**Service locator in API Resources** — an anti-pattern, but sometimes unavoidable: Laravel instantiates API Resources outside the container, making constructor injection impossible. In these cases, use a service locator helper (e.g. a trait wrapping `app()`) as a last resort. Never apply this pattern in models or anywhere constructor injection is available.

### Configuration

Never call `env()` in application code — it returns `null` when config cache is active.

```php
// Bad
$key = env('API_KEY');

// Good — in config/api.php
return ['key' => env('API_KEY')];

// Good — injected
#[Config('api.key')] private string $apiKey
```

### Date & Time

Never use `now()`, `new \DateTime()`, `new \DateTimeImmutable()`, or `Carbon::now()` in services, repositories, or value objects. Inject `Psr\Clock\ClockInterface`:

```php
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

readonly class MyService
{
    public function __construct(
        private ClockInterface $clock = new Clock(),
    ) {}

    public function doWork(): void
    {
        $now = $this->clock->now(); // \DateTimeImmutable
    }
}
```

Exception: config files and migration files (no DI container, time is a deployment constant).

### DocBlocks

Write only when PHP types are insufficient:

- Complex array shapes (`@param array{...}`)
- Generic collections (`@return Collection<int, User>`)
- Non-obvious intent or side effects

Inside function bodies, use `//` or `/* */` — never `/** */`.

---

## API Stability

Classes and methods marked `@api` form the **stable public surface** of a domain. `@api` on a class with no method-level `@api` means the entire public + protected surface is stable.

- Signatures won't change until next major version
- Removal requires `@deprecated` with when + migration path
- `@api` classes are **never `final`** — must remain open for decoration

Everything without `@api` is internal and may change at any time.

### Decoration

Decorate `@api` services via `$this->app->extend()`. To avoid calling the constructor of the original (which would double-wire dependencies), use a decorator utility that copies constructor-injected properties from the original instance via reflection:

```php
class DecoratedOrderService extends OrderService
{
    // decorator utility copies all properties from the original via reflection
    use DecoratorTrait;

    public function getOrders(): OrderCollection
    {
        return $this->filter(parent::getOrders());
    }
}

// In a ServiceProvider
$this->app->extend(OrderService::class, function (OrderService $original) {
    return DecoratedOrderService::createDecoratedOf($original);
});
```

Internal (non-`@api`) classes may be `final` and carry no such guarantee.

---

## Quick Checklist

- `declare(strict_types=1)` in every PHP file
- All parameters and return types declared
- Dependencies injected via constructor or `#[Config]`/`#[Cache]` attributes
- No facades in services, repositories, or value objects
- Service locator only where constructor injection is genuinely impossible (not in models)
- No `env()` outside config files
- All DB access through a `Repository` — no static model calls in services
- Models: no business logic, no facades, no query scopes, no service locator
- Value objects: `readonly` with `from...`/`tryFrom...` factory methods
- Enums for all constrained string/int values
- No `now()`, `Carbon::now()`, `new \DateTime()` — inject `Psr\Clock\ClockInterface`
- Native PHP classes always fully qualified (leading `\`)
- No `Utils/` directories — classify more precisely
