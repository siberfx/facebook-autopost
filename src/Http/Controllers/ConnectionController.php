<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Support\StatusMessages;
use Siberfx\FacebookAutopost\Support\Ui;

final class ConnectionController extends Controller
{
    public function __construct(private readonly FacebookManager $facebook) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->facebook->connection()->toArray()]);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $revoked = $this->facebook->disconnect();
        $message = $revoked
            ? 'Facebook disconnected.'
            : 'Facebook disconnected here, but Facebook did not confirm revoking the token. Remove the app under Facebook → Settings → Data privacy → Permitted services if needed.';

        if (! $request->expectsJson() && Ui::enabled()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->facebook->connection()->toArray(),
        ]);
    }
}
