<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TeamController — Team Management Within Organizations
 *
 * Allows creating/managing teams and assigning/removing members.
 */
class TeamController extends Controller
{
    public function index(Request $request, int $orgId): JsonResponse
    {
        Organization::findOrFail($orgId); // Ensure org exists

        $teams = Team::forOrganization($orgId)
            ->withCount('users')
            ->paginate($request->input('per_page', 20));

        return response()->json($teams);
    }

    public function show(int $orgId, int $teamId): JsonResponse
    {
        $team = Team::forOrganization($orgId)->with(['users.roles'])->findOrFail($teamId);
        return response()->json($team);
    }

    public function store(Request $request, int $orgId): JsonResponse
    {
        Organization::findOrFail($orgId);

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
        ]);

        $team = Team::create([
            ...$validated,
            'organization_id' => $orgId,
            'slug'            => Str::slug($validated['name']),
        ]);

        return response()->json($team, 201);
    }

    public function update(Request $request, int $orgId, int $teamId): JsonResponse
    {
        $team = Team::forOrganization($orgId)->findOrFail($teamId);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $team->update($validated);

        return response()->json($team);
    }

    public function destroy(int $orgId, int $teamId): JsonResponse
    {
        $team = Team::forOrganization($orgId)->findOrFail($teamId);
        $team->delete();

        return response()->json(['message' => 'Team deleted.', 'id' => $teamId]);
    }

    /**
     * Add a user to a team.
     */
    public function addMember(Request $request, int $orgId, int $teamId): JsonResponse
    {
        $team = Team::forOrganization($orgId)->findOrFail($teamId);

        $request->validate([
            'user_id'      => 'required|integer|exists:users,id',
            'role_in_team' => 'sometimes|string|in:lead,member',
        ]);

        $team->users()->syncWithoutDetaching([
            $request->user_id => ['role_in_team' => $request->input('role_in_team', 'member')]
        ]);

        return response()->json(['message' => 'Member added to team.']);
    }

    /**
     * Remove a user from a team.
     */
    public function removeMember(int $orgId, int $teamId, int $userId): JsonResponse
    {
        $team = Team::forOrganization($orgId)->findOrFail($teamId);
        $team->users()->detach($userId);

        return response()->json(['message' => 'Member removed from team.']);
    }
}
