<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Om :subject',
        'close' => 'Stäng',
    ],

    'page_title' => 'Var finns mina data?',
    'intro' => 'Beatrax lagrar allt på den här enheten. Det finns ingen Beatrax-server och inget molnkonto. Ett enda anrop går ut av sig själv — en kontroll av om det finns en ny version, som du kan stänga av. Allt annat väntar på dig: en inkorg, en bank via Enable Banking, en daglig hämtning av växelkurser, de enheter du parkopplar för synkronisering, en relay du ställer in och varje länk du klickar på. Var och en säger det på skärmen där du slår på den.',

    'lives_here' => 'Dina data finns här',
    'copy' => 'Kopiera',
    'copied' => 'Kopierat',

    'location' => [
        'database' => 'Databas:',
        'artifacts_imports' => 'Importerade kontoutdrag:',
        'artifacts_mail' => 'Inläst e-post:',
        'artifacts_drop' => 'Bevakad mapp:',
        'backups' => 'Säkerhetskopior:',
        'secrets' => 'Inloggningsuppgifter för kopplingar:',
        'logs' => 'Loggar:',
    ],

    'copy_aria' => [
        'database' => 'Kopiera sökvägen till databasen till urklipp',
        'artifacts_imports' => 'Kopiera sökvägen till importerade kontoutdrag till urklipp',
        'artifacts_mail' => 'Kopiera sökvägen till inläst e-post till urklipp',
        'artifacts_drop' => 'Kopiera sökvägen till den bevakade mappen till urklipp',
        'backups' => 'Kopiera sökvägen till säkerhetskopior till urklipp',
        'secrets' => 'Kopiera sökvägen till inloggningsuppgifter för kopplingar till urklipp',
        'logs' => 'Kopiera sökvägen till loggar till urklipp',
    ],

    'artifacts_heading' => 'Dina källdokument ligger inte i säkerhetskopian',
    'artifacts_body' => 'En säkerhetskopia innehåller databasen och inget annat. Kontoutdragen du importerade, e-posten som inläsningen hämtade och kvittona du la i den bevakade mappen blir kvar där de är, i de tre mapparna ovan. Att lägga en säkerhetskopia på ett tryggt ställe kopierar dem inte, så ett fullständigt arkiv innebär att du tar med de mapparna också — eller använder Exportera allt nedan, som packar ihop dem med säkerhetskopian åt dig.',

    'export_heading' => 'Exportera allt',
    'export_body' => 'Ett arkiv med en krypterad kopia av din databas och varje källdokument du har gett Beatrax. Packa upp det var du vill, så ligger dina dokument där precis som förut, i mapparna de kom från.',
    'export_passphrase_label' => 'Lösenfras för databasen',
    'export_confirm_label' => 'Upprepa lösenfrasen',
    'export_passphrase_hint' => 'Databasen inuti arkivet krypteras med den här lösenfrasen och går inte att öppna utan den, så välj något du fortfarande har kvar sedan. Dina källdokument följer med som de är, så förvara arkivet på ett ställe du litar på.',
    'export_cta' => 'Exportera allt som ZIP',
    'export_working' => 'Arkivet byggs…',

    'delete_heading' => 'Ta bort dina data',
    'delete_intro' => 'Dina data är filer på den här enheten, så att radera dem betyder att radera de filerna. Det finns ingen knapp här som gör det åt dig, och det är med flit: det är filsystemet som faktiskt håller din historik, och en knapp som tömde ett par tabeller men lät filerna ligga kvar vore sämre än ingenting.',
    'delete_uninstall' => 'Att avinstallera Beatrax raderar inte dina data. Det är medvetet — en oavsiktlig avinstallation får inte förstöra flera års historik — så allt nedan blir kvar på enheten tills du tar bort det själv.',
    'delete_list_intro' => 'Radera var och en av de här för att ta bort alla spår:',
    'delete_journal_note' => 'Bredvid databasen ligger två journalfiler, :wal och :shm. Dina senaste ändringar finns i dem tills de skrivs in i databasen, så radera alla tre tillsammans.',
    'no_telemetry' => 'Det finns ingen telemetri att välja bort och inget fjärrkonto att avsluta.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Den tidsrymd dina budgetar, din översikt och varje siffra för ”den här perioden” mäts över. ”:label” avgör var den börjar — sätt den till dagen efter lön, så innehåller en period de pengar som betalades ut för att täcka den. Att flytta den dagen omplacerar varje kuvertbelopp du redan har fördelat, och där två gamla perioder faller ihop till en ny läggs deras belopp samman; att flytta tillbaka dagen delar inte upp dem igen.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Allt Beatrax vet att du har, minus allt du är skyldig: saldot på varje konto du har importerat eller kopplat, där kort- och lånesaldon räknas emot dig. Det är aldrig mer fullständigt än det du har matat in — ett konto Beatrax aldrig har sett finns inte i den här siffran. Saldon i en annan valuta räknas om till kursen som visas under siffran, och det som inte gick att prissätta namnges där i stället för att tyst falla bort.',
];
