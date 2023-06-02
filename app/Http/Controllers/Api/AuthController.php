<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        $user->save();
        $token = $user->createToken('Token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'message' => 'Đăng ký tài khoản thành công!',
            'access_token' => $token,
            'user' => [
                "name" => $user->name,
                "email" => $user->email
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'ok' => false,
                'message' => 'Tài khoản hoặc mật khẩu không chính xác!',
            ], 401);
        }

        $user = $request->user();
        $token = $user->createToken('Token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'access_token' => $token,
            'user' => [
                "name" => $user->name,
                "email" => $user->email
            ]
        ], 200);
    }

    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();

        return response()->json(['ok' => true, 'message' => 'Đăng xuất thành công!']);
    }

    public function getUser(Request $request)
    {
        $user =  auth('sanctum')->user();

        $response = [
            'ok' => true,
            'user' => [
                "name" => $user->name,
                "email" => $user->email
            ]
        ];

        return response($response, 201);
    }
}
