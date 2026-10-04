<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Troca e-mail e senha por um token de acesso.
     */
    public function login(LoginRequest $request): UserResource
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->withToken($user, $request->validated('device_name', 'api'));
    }

    /**
     * Retorna o usuário autenticado.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Revoga o token usado na requisição.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Emite um token para o usuário e o inclui na resposta.
     */
    private function withToken(User $user, string $deviceName): UserResource
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return (new UserResource($user))->additional([
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
