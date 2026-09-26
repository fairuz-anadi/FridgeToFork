<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|alpha_dash|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create($validated);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'token' => $token,
            'user' => $user,
        ], Response::HTTP_CREATED);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginInput = trim($validated['email']);

        $user = User::where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid username/email or password.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Google Sign-In via id_token (Google Identity Services button).
     *
     * Verifies the JWT with Google's tokeninfo endpoint. In the local
     * environment the audience check is skipped because localhost is
     * typically not an authorised JavaScript origin in the Cloud Console.
     */
    public function google(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        $googleResponse = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $validated['id_token'],
        ]);

        if ($googleResponse->failed()) {
            return response()->json([
                'message' => 'Google token verification failed. Make sure your Google Client ID is correct and this origin is authorised.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload       = $googleResponse->json();
        $email         = $payload['email']   ?? null;
        $googleId      = $payload['sub']     ?? null;
        $name          = $payload['name']    ?? ($payload['given_name'] ?? 'Google User');
        $expectedAud   = config('services.google.client_id');
        $isLocal       = app()->environment('local');

        // Audience check: skip in local dev (localhost is not a registered
        // JavaScript origin, so Google issues tokens with a different aud
        // when tested via the GSI button on a non-registered origin).
        if (!$isLocal && $expectedAud && ($payload['aud'] ?? null) !== $expectedAud) {
            return response()->json([
                'message' => 'Google token audience mismatch.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$email || !$googleId) {
            return response()->json([
                'message' => 'Incomplete Google profile data.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->upsertGoogleUser($email, $googleId, $name);
    }

    /**
     * OAuth 2.0 authorisation-code callback (used by the popup flow).
     * The frontend opens a popup to Google, Google redirects here with ?code=…
     * and we exchange the code for tokens server-side (no origin restriction).
     */
    public function googleCallback(Request $request)
    {
        $code  = $request->query('code');
        $error = $request->query('error');

        if ($error || !$code) {
            return $this->popupClose('error', 'Google sign-in was cancelled or denied.');
        }

        $clientId     = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $redirectUri  = config('app.url') . '/api/auth/google/callback';

        // Exchange code for tokens
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if ($tokenResponse->failed()) {
            return $this->popupClose('error', 'Could not exchange Google code for tokens.');
        }

        $accessToken = $tokenResponse->json('access_token');

        // Fetch user info
        $userInfo = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if ($userInfo->failed()) {
            return $this->popupClose('error', 'Could not fetch Google profile.');
        }

        $profile  = $userInfo->json();
        $email    = $profile['email']    ?? null;
        $googleId = $profile['sub']      ?? null;
        $name     = $profile['name']     ?? ($profile['given_name'] ?? 'Google User');

        if (!$email || !$googleId) {
            return $this->popupClose('error', 'Incomplete Google profile.');
        }

        $jsonResponse = $this->upsertGoogleUser($email, $googleId, $name);
        $data = $jsonResponse->getData(true);

        return $this->popupClose('success', json_encode($data));
    }

    /**
     * Upsert the Google-authenticated user and return a Sanctum token response.
     */
    private function upsertGoogleUser(string $email, string $googleId, string $name)
    {
        $baseUsername = Str::slug(Str::before($email, '@'), '_') ?: 'user';
        $username     = $baseUsername;
        $suffix       = 1;

        while (
            User::where('username', $username)
                ->where('google_id', '!=', $googleId)
                ->exists()
        ) {
            $username = $baseUsername . '_' . $suffix;
            $suffix++;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // Link Google to the existing account; keep its name, username
            // and password so email log-in still works.
            $user->forceFill([
                'google_id'         => $googleId,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        } else {
            $user = User::create([
                'name'              => $name,
                'email'             => $email,
                'username'          => $username,
                'google_id'         => $googleId,
                'email_verified_at' => now(),
                'password'          => Hash::make(Str::random(24)),
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Google login successful.',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    /**
     * Return an HTML page that postMessages to the opener and closes itself.
     */
    private function popupClose(string $status, string $payload): \Illuminate\Http\Response
    {
        $origin = config('app.client_url', 'http://localhost:5173');
        $json   = $status === 'success' ? $payload : json_encode(['error' => $payload]);

        $html = <<<HTML
        <!doctype html><html><body><script>
          try {
            window.opener.postMessage({ status: "{$status}", payload: {$json} }, "{$origin}");
          } catch(e) {}
          window.close();
        </script></body></html>
        HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->loadCount('recipes'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
