<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count muudatuse tegi Beatraxi uuem versioon|:count muudatust tegi Beatraxi uuem versioon',
        'body' => 'See, mis lükati tagasi, nimetab midagi, mida selles Beatraxi versioonis ei ole, nii et sellel seadmel polnud seda kuhugi panna. See on endiselt seadmes, kus see tehti, ja midagi sinu omast ei kustutatud.',
        'action' => 'Uuenda Beatrax selles seadmes. Pärast uuendust tehtud muudatused saabuvad tavapäraselt, kuid midagi juba tagasi lükatut enam uuesti ei saadeta — tee muudatus siin uuesti, kui vajad seda ka selles seadmes.',
    ],
    'untrusted_author' => [
        'summary' => ':count muudatuse allkirjastas seade, mida see seade ei tunne|:count muudatust allkirjastas seade, mida see seade ei tunne',
        'body' => 'See, mis lükati tagasi, tuli seadmest, mida pole selle seadmega kunagi seotud, või seadmest, mille sa eemaldasid. Siia ei kirjutatud midagi ja midagi siin juba olnut ei muudetud.',
        'action' => 'Kui eemaldasid selle seadme ise, siis just seda eemaldamine teebki ja parandada pole midagi. Kui mitte, vaata selle lehe seadmete loendit.',
    ],
    'not_verified' => [
        'summary' => ':count muudatus ei läbinud selle seadme turvakontrolli|:count muudatust ei läbinud selle seadme turvakontrolli',
        'body' => 'Allkiri ei sobinud seadmega, mis väitis end muudatuse teinuks, või oli muudatus adresseeritud teisele kontole. Siia ei kirjutatud midagi. Sinu enda seadmete vahel ei tohiks seda juhtuda.',
        'action' => 'Vaata selle lehe seadmete loendit ja eemalda kõik, mida sa ei tunne. Kui iga sealne seade on sinu oma ja see kordub, on tegu Beatraxi veaga, mitte millegagi, mida saaksid siit korda teha.',
    ],
    'diverged' => [
        'summary' => ':count muudatus teisest seadmest jäi siia salvestamata|:count muudatust teisest seadmest jäi siia salvestamata',
        'body' => 'Siia saabus midagi, mida see seade ei suutnud talletada: kirje, millel on osa endast puudu, kuupäev, mida ei ole olemas, jaotus, mis enam kokku ei lähe, kirje, millele kaks seadet olid juba andnud sama identiteedi, või kustutus millegi kohta, mis on siin veel kasutuses. See, mis lükati tagasi, on sinu teises seadmes, aga mitte selles, nii et kaks seadet ei sisalda enam sama.',
        'action' => 'Võrdle teises seadmes olevat kirjet sellega, mida siin näed, ja tee muudatus siin uuesti — või kustuta see siin uuesti, kui midagi, mille sa mujal eemaldasid, on siin veel alles. Tagasi lükatut iseenesest uuesti ei saadeta.',
    ],
    'last_seen' => 'Viimane: :when',
];
