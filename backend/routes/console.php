<?php

use Illuminate\Support\Facades\Schedule;

// Filet de sécurité si un webhook du service de paiement est perdu.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
