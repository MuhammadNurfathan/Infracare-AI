<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EscalationController;

Route::post('/chat', [ChatController::class, 'chat']);
Route::post('/chat/escalate-email', [EscalationController::class, 'sendEmail']);

