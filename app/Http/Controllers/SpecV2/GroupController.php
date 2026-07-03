<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateGroupRequest;
use App\Http\Requests\SpecV2\ListGroupsRequest;
use App\Http\Requests\SpecV2\PaginatedSpecRequest;
use App\Http\Requests\SpecV2\ReplaceGroupMembersRequest;
use App\Http\Requests\SpecV2\UpdateGroupMembersRequest;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\GroupNotFoundException;
use App\Services\SpecV2\Exceptions\InvalidGroupIdentifierException;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(ListGroupsRequest $request): JsonResponse
    {
        return response()->json($this->spec->groups->list($request->filters(), $request->page(), $request->perPage()));
    }

    public function store(CreateGroupRequest $request): JsonResponse
    {
        try {
            $group = $this->spec->groups->create($request->validated(), $request->user());
        } catch (ApplicationNotFoundException|InvalidGroupIdentifierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($group, 201);
    }

    public function show(string $groupId): JsonResponse
    {
        try {
            return response()->json($this->spec->groups->show($groupId));
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function destroy(string $groupId): JsonResponse
    {
        try {
            $this->spec->groups->delete($groupId);
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->noContent();
    }

    public function users(string $groupId, PaginatedSpecRequest $request): JsonResponse
    {
        try {
            return response()->json($this->spec->groups->listMembers($groupId, $request->page(), $request->perPage()));
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function replaceUsers(string $groupId, ReplaceGroupMembersRequest $request): JsonResponse
    {
        try {
            return response()->json($this->spec->groups->replaceMembers($groupId, $request->validated('users')));
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function updateUsers(string $groupId, UpdateGroupMembersRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            return response()->json($this->spec->groups->updateMembers($groupId, $payload['add'] ?? [], $payload['remove'] ?? []));
        } catch (GroupNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
