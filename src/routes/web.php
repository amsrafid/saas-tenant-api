<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Scramble::registerJsonSpecificationRoute('docs/api.json')->middleware(RestrictedDocsAccess::class);

Route::view('api/documentation', 'swagger')->middleware(RestrictedDocsAccess::class);
