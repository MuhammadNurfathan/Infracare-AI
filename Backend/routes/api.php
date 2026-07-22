<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EscalationController;
use App\Http\Controllers\DingTalkAlarmController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

Route::post('/chat', [ChatController::class, 'chat']);
Route::post('/chat/escalate-email', [EscalationController::class, 'sendEmail']);

