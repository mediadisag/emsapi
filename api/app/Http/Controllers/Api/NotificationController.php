<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /*
    |==========================================================================
    | GET /notifications?unread_only=1
    | Current user's own notifications, latest first.
    |==========================================================================
    */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Notification::where('user_id', $user->id);

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $rows = $query->orderBy('created_at', 'desc')->limit(50)->get();

        return response()->json([
            'status'      => true,
            'unread_count' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
            'rows'        => $rows,
        ]);
    }

    /*
    |==========================================================================
    | PUT /notifications/{id}/read
    | Mark a single notification as read (only if it belongs to the
    | requesting user).
    |==========================================================================
    */
    public function markRead(Request $request, $id)
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)->where('user_id', $user->id)->first();

        if (!$notification) {
            return response()->json(['status' => false, 'message' => 'Notification not found.'], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['status' => true]);
    }

    /*
    |==========================================================================
    | PUT /notifications/read-all
    | Mark all of the current user's notifications as read (e.g. when they
    | open the notifications dropdown).
    |==========================================================================
    */
    public function markAllRead(Request $request)
    {
        $user = $request->user();

        Notification::where('user_id', $user->id)->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['status' => true]);
    }
}
