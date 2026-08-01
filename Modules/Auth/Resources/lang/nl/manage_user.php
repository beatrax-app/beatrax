<?php

declare(strict_types=1);

return [
    'page_title' => ':name beheren · beatrax',
    'heading' => ':name beheren',
    'subtitle' => 'Codes voor deze gebruiker bekijken, opnieuw instellen of opnieuw genereren.',

    'set_password' => [
        'heading' => 'Nieuw wachtwoord voor deze gebruiker instellen',
        'description' => 'Bij de volgende keer inloggen wordt gevraagd om een wachtwoord te kiezen.',
        'open' => 'Nieuw wachtwoord voor deze gebruiker instellen',
        'body' => 'Stel een nieuw wachtwoord in voor :name. Bij de volgende keer inloggen wordt gevraagd om een wachtwoord te kiezen.',
        'label' => 'Nieuw wachtwoord',
        'submit' => 'Wachtwoord instellen',
        'cancel' => 'Annuleren',
    ],

    'regenerate' => [
        'heading' => 'Herstelcodes voor deze gebruiker opnieuw genereren',
        'description' => 'Oude codes worden ongeldig gemaakt.',
        'open' => 'Herstelcodes voor deze gebruiker opnieuw genereren',
        'body' => 'Hun bestaande ongebruikte codes werken niet meer. Je ziet de 10 nieuwe codes één keer en kunt ze doorgeven.',
        'confirm_label' => 'Typ de gebruikersnaam om door te gaan',
        'submit' => 'Codes opnieuw genereren',
        'keep' => 'Huidige codes behouden',
        'download' => 'Downloaden als .txt',
    ],

    'error_min_length' => 'Gebruik minimaal 12 tekens.',
    'password_set' => 'Wachtwoord ingesteld voor :name. Bij de volgende keer inloggen wordt gevraagd om een wachtwoord te kiezen.',
    'codes_regenerated' => 'Tien nieuwe herstelcodes gegenereerd voor :name.',
];
