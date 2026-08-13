<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Get the authenticated user's recent notifications — both read and
     * unread, newest first. Read ones stay visible (just visually muted on
     * the frontend and excluded from the unread badge count) instead of
     * vanishing the moment they're clicked, which previously made it look
     * like notifications were being deleted rather than just acknowledged.
     * Capped to the most recent 30 so this stays a quick dropdown, not a
     * full notification history page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $user->notifications()->latest()->limit(30)->get(),
            // The frontend's bell badge used to count unread rows within
            // this same capped list — correct as long as unread notifications
            // never exceeded 30, but any owner with more than that sitting
            // unread would see the badge silently undercount instead of
            // reflecting their real total. A dedicated, unlimited count
            // query is the only way this stays accurate at any volume.
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        
        if ($notification) {
            $notification->markAsRead();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark a specific notification back as unread — the counterpart to
     * markAsRead(), for the "Mark as unread" row action.
     */
    public function markAsUnread(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsUnread();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Remove a single notification from the panel for good — distinct from
     * markAsRead(), which keeps it visible just muted. This is the explicit
     * "I don't need to see this again" action.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->delete();
        }

        return response()->json(['success' => true]);
    }
}
