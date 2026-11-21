<?php
use Illuminate\Support\Facades\Route;
Route::get('/health', fn () => response()->json(['status'=>'ok','application'=>'digital-creator-marketplace','version'=>'0.1.0']));
