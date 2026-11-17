<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('galleries:remind')->everyFifteenMinutes()->withoutOverlapping();
