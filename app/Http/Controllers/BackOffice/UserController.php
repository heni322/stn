<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct()
    {
        // Apply middleware to all routes except for 'index' or 'show' if needed
        $this->middleware(['auth:api', 'role:Admin']);
    }

    public function index(Request $request)
    {
        try {
            // Filters
            $filterFirstName = $request->input('first_name');
            $filterLastName = $request->input('last_name');
            $filterEmail = $request->input('email');
            $filterRole = $request->input('role');

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = Str::upper($request->input('sort_order', 'desc'));

            // Pagination
            $paginate = filter_var($request->input('paginate'), FILTER_VALIDATE_BOOLEAN);
            $perPage = $request->input('per_page', 10);
            $page = (int) $request->input('page', 1);

            // Build query
            $query = User::query()
                ->when($filterFirstName, fn($q) => $q->where('first_name', 'LIKE', "%{$filterFirstName}%"))
                ->when($filterLastName, fn($q) => $q->where('last_name', 'LIKE', "%{$filterLastName}%"))
                ->when($filterEmail, fn($q) => $q->where('email', 'LIKE', "%{$filterEmail}%"))
                ->when($filterRole, fn($q) => $q->whereHas('roles', fn($q) => $q->where('name', $filterRole)))
                ->orderBy($sortBy, $sortOrder);

            // Generate cache key
            $cacheKey = "users_{$filterFirstName}_{$filterLastName}_{$filterEmail}_{$filterRole}_{$sortBy}_{$sortOrder}_{$perPage}_page_{$page}";

            // Store cache key for later clearing
            $cacheKeys = Cache::get('user_cache_keys', []);
            if (!in_array($cacheKey, $cacheKeys)) {
                $cacheKeys[] = $cacheKey;
                Cache::put('user_cache_keys', $cacheKeys, 3600);
            }

            if ($paginate) {
                $users = Cache::remember($cacheKey, 3600, function () use ($query, $perPage, $page) {
                    return $query->paginate($perPage, ['*'], 'page', $page);
                });

                return response()->json([
                    'success' => true,
                    'data' => UserResource::collection($users->items()),
                    'pagination' => [
                        'totalItems' => $users->total(),
                        'currentPage' => $users->currentPage(),
                        'totalPages' => $users->lastPage(),
                        'limit' => $users->perPage(),
                    ],
                ]);
            } else {
                $users = Cache::remember($cacheKey, 3600, function () use ($query) {
                    return $query->get();
                });

                return response()->json([
                    'success' => true,
                    'data' => UserResource::collection($users),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching users.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            // Using validate method with custom error handling
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'role' => 'required|string|exists:roles,name',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = handleImageUpload($request, 'image', 'users/images');
            }

            // Create the user
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'image' => $imagePath ? $imagePath['path'] : null,
            ]);

            // Assign role to the user
            $role = Role::where('name', $request->role)
                    ->where('guard_name', 'api')
                    ->first();

            if ($role) {
                $user->assignRole($role);
            }

            DB::commit();
            $this->clearUserCache();

            return response()->json(new UserResource($user), 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user.',
            ], 500);
        }
    }

    // Helper method to clear cache
    private function clearUserCache()
    {
        $cacheKeys = Cache::get('user_cache_keys', []);
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('user_cache_keys');
    }

    public function show(User $user)
    {
        return response()->json(new UserResource($user));
    }

    public function update(Request $request, User $user)
    {
        // Start transaction
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|string|max:255',
                'last_name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id),
                ],
                'password' => 'sometimes|string|min:8',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'role' => 'sometimes|string|exists:roles,name',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = handleImageUpload($request, 'image', 'users/images');
                $user->image = $imagePath['path'];
            }

            // Update user details
            $user->update([
                'first_name' => $request->first_name ?? $user->first_name,
                'last_name' => $request->last_name ?? $user->last_name,
                'email' => $request->email ?? $user->email,
                'password' => $request->password ? Hash::make($request->password) : $user->password,
            ]);

            // Update role if provided
            if ($request->has('role')) {
                $role = Role::where('name', $request->role)->where('guard_name', 'api')->first();
                if ($role) {
                    $user->syncRoles([$role]);
                }
            }

            // Commit transaction
            DB::commit();
            $this->clearUserCache();

            return response()->json(new UserResource($user));

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            Log::error('Error updating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user.',
            ], 500);
        }
    }

    public function destroy(User $user)
    {
        try {
            // Delete the user
            $user->delete();
            $this->clearUserCache();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the user.',
            ], 500);
        }
    }

    public function destroySelected(Request $request)
    {
        try {
            // Get the selected user IDs from the request
            $userIds = $request->input('user_ids', []);

            // Check if any IDs were provided
            if (empty($userIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user IDs provided.',
                ], 400);
            }

            // Delete the users with the provided IDs
            User::whereIn('id', $userIds)->delete();

            // Clear user cache (if applicable)
            $this->clearUserCache();

            return response()->json([
                'success' => true,
                'message' => 'Selected users deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting selected users: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the selected users.',
            ], 500);
        }
    }
}
