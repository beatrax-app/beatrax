<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count módosítást a Beatrax egy újabb verziója készített|:count módosítást a Beatrax egy újabb verziója készített',
        'body' => 'Az elutasított módosítás olyasmire hivatkozik, ami a Beatrax ebben a verziójában nincs meg, ezért ennek az eszköznek nem volt hova tennie. Továbbra is azon az eszközön van, amelyik készítette, és semmi sem törlődött abból, ami a tiéd.',
        'action' => 'Frissítsd a Beatraxot ezen az eszközön. A frissítés után készült módosítások a szokott módon megérkeznek, de az egyszer már elutasítottat nem küldi el újra semmi — készítsd el a módosítást itt még egyszer, ha ezen az eszközön is szükséged van rá.',
    ],
    'untrusted_author' => [
        'summary' => ':count módosítást olyan eszköz írt alá, amelyet ez az eszköz nem ismer|:count módosítást olyan eszköz írt alá, amelyet ez az eszköz nem ismer',
        'body' => 'Az elutasított módosítás olyan eszközről érkezett, amely soha nem volt párosítva ezzel, vagy olyanról, amelyet eltávolítottál. Ide semmi sem íródott, és semmi nem változott abból, ami már itt volt.',
        'action' => 'Ha te magad távolítottad el azt az eszközt, pontosan ezt teszi az eltávolítás, és nincs mit helyrehozni. Ha nem te voltál, nézd meg az eszközök listáját ezen az oldalon.',
    ],
    'not_verified' => [
        'summary' => ':count módosítás nem ment át a biztonsági ellenőrzésen ezen az eszközön|:count módosítás nem ment át a biztonsági ellenőrzésen ezen az eszközön',
        'body' => 'Egy aláírás nem illett ahhoz az eszközhöz, amely azt állította, hogy ő készítette a módosítást, vagy a módosítás egy másik fióknak szólt. Ide semmi sem íródott. A saját eszközeid között ennek nem szabadna előfordulnia.',
        'action' => 'Nézd meg az eszközök listáját ezen az oldalon, és távolíts el mindent, amit nem ismersz fel. Ha ott minden eszköz a tiéd, és ez továbbra is előfordul, az a Beatrax hibája, nem pedig valami, amit innen helyre tudsz hozni.',
    ],
    'diverged' => [
        'summary' => ':count módosítást egy másik eszközről nem sikerült itt elmenteni|:count módosítást egy másik eszközről nem sikerült itt elmenteni',
        'body' => 'Olyasmi érkezett, amit ez az eszköz nem tudott tárolni: egy rekord, amelyből hiányzik egy része, egy dátum, amely nem létezik, egy felosztás, amely már nem jön ki, egy rekord, amelynek két eszköz már ugyanazt az azonosítót adta, vagy egy törlés olyasmire, ami itt még használatban van. Az elutasított módosítás a másik eszközödön van, ezen nem, így a kettő már nem ugyanazt tartalmazza.',
        'action' => 'Hasonlítsd össze a másik eszközödön lévő rekordot azzal, amit itt látsz, és készítsd el újra a módosítást itt — vagy töröld itt ismét, ha valami, amit máshol eltávolítottál, itt még megvan. Az elutasítottat magától nem küldi el újra semmi.',
    ],
    'last_seen' => 'Legutóbbi: :when',
];
