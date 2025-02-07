<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Start transaction to ensure data consistency
        DB::beginTransaction();

        try {
            // Validate the incoming request
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'required|string|in:Client,Provider',
                'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            ]);

            // Handle image upload if it exists
            $validated['image'] = $this->handleImageUpload($request);

            // Create the user with the validated data
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'image' => $validated['image'],
            ]);
            $role = Role::findByName($validated['role'], 'api');

            // Assign the role to the user
            $user->assignRole($role);

            // Commit the transaction to save all changes
            DB::commit();

            // Return response with the created user data
            return response()->json([
                'status' => true,
                'message' => 'User registered successfully.',
                'user' => new UserResource($user),
            ], 201);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollback();

            // Log the exception for debugging
            Log::error('User registration failed: ', ['error' => $e->getMessage()]);

            // Return error response
            return response()->json([
                'status' => false,
                'message' => 'An error occurred during registration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            // Validate the incoming request
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            // Attempt to authenticate using the API guard
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            // Create a personal access token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return the response with user data and token
            return response()->json([
                'status' => true,
                'message' => 'Login successful.',
                'user' => new UserResource($user),
                'token' => $token,
            ], 200);

        } catch (\Exception $e) {
            // If any error occurs, return a response with the error message
            return response()->json([
                'status' => false,
                'message' => 'An error occurred during login.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Log out the user
    public function logout(Request $request)
    {
        try {
            // Revoke the current access token for the authenticated user
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'status' => true,
                'message' => 'Logged out successfully.',
            ], 200);

        } catch (\Exception $e) {
            // If any error occurs, return a response with the error message
            return response()->json([
                'status' => false,
                'message' => 'An error occurred during logout.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // Get user details
    public function user(Request $request)
    {
        return response()->json(new UserResource($request->user()));
    }

    /**
     * Handle image upload and resizing
     *
     * @param Request $request
     * @return string|null
     */
    protected function handleImageUpload(Request $request)
    {
        if ($request->hasFile('image')) {
            $imageFile = $request->file('image');

            // Generate a unique file name
            $filename = Str::uuid() . '.' . $imageFile->getClientOriginalExtension();

            // Store the image and resize it
            $imagePath = $imageFile->storeAs('public/users', $filename);

            // Resize the image using Image intervention
            Image::make(Storage::path($imagePath))
                ->resize(800, 600, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                })
                ->save(Storage::path($imagePath));

            return url('storage/users/' . $filename);
        }

        return null;
    }
}
