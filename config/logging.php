<?php
use Monolog\Handler\NullHandler;
return ['default'=>env('LOG_CHANNEL','stack'),'deprecations'=>['channel'=>'null','trace'=>false],'channels'=>['stack'=>['driver'=>'stack','channels'=>['daily'],'ignore_exceptions'=>false],'daily'=>['driver'=>'daily','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','info'),'days'=>14,'replace_placeholders'=>true],'single'=>['driver'=>'single','path'=>storage_path('logs/laravel.log'),'level'=>'debug'],'null'=>['driver'=>'monolog','handler'=>NullHandler::class]]];
