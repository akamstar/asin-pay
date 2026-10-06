<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frais de dossier
    |--------------------------------------------------------------------------
    |
    | Montant fixe (FCFA) ajouté à chaque demande, en plus de prix x quantité.
    | Il est figé dans la demande au moment de sa création.
    |
    */

    'fees' => (int) env('FRAIS_DOSSIER', 100),

    /*
    |--------------------------------------------------------------------------
    | Quantité maximale par demande
    |--------------------------------------------------------------------------
    */

    'max_quantity' => (int) env('DEMANDE_QUANTITE_MAX', 10),

];
