<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateGroupRequest;
use App\Http\Requests\SpecV2\ListGroupsRequest;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Http\Requests\SpecV2\ReplaceGroupMembersRequest;
use App\Http\Requests\SpecV2\UpdateGroupMembersRequest;
use App\Http\Resources\SpecV2\GroupCollection;
use App\Http\Resources\SpecV2\GroupResource;
use App\Services\Authorization\ApiActorResolver;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListGroupsRequest $request): JsonResponse
    {
        return response()->json(
            (new GroupCollection($this->spec->groups->list($request->filters(), $request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function store(CreateGroupRequest $request, ApiActorResolver $actors): JsonResponse
    {
        $group = $this->spec->groups->create($request->validated(), $actors->resolve($request));

        return response()->json((new GroupResource($group))->resolve($request), 201);
    }

    public function show(Request $request, string $groupId): JsonResponse
    {
        return response()->json((new GroupResource($this->spec->groups->show($groupId)))->resolve($request));
    }

    public function destroy(string $groupId): JsonResponse
    {
        $this->spec->groups->delete($groupId);

        return response()->noContent();
    }

    public function users(string $groupId, PaginatedSpecRequest $request): JsonResponse
    {
        return response()->json($this->spec->groups->listMembers($groupId, $request->page(), $request->perPage()));
    }

    public function replaceUsers(string $groupId, ReplaceGroupMembersRequest $request): JsonResponse
    {
        return response()->json($this->spec->groups->replaceMembers($groupId, $request->validated('users')));
    }

    public function updateUsers(string $groupId, UpdateGroupMembersRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json($this->spec->groups->updateMembers($groupId, $payload['add'] ?? [], $payload['remove'] ?? []));
    }
}
