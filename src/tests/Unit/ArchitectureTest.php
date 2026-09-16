<?php

arch('the current user is read only through AuthUserService')
    ->expect('App')
    ->not->toUse(['auth', 'Illuminate\Support\Facades\Auth']);
