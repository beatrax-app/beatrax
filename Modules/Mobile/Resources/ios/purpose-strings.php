<?php

declare(strict_types=1);

// iOS reads these out of the app bundle before any PHP runs, keyed by
// Info.plist key rather than by a translation key, and nothing renders them, so
// they sit outside Resources/lang/ where every line must have a call site. The
// reader is a build script that runs with no framework and no translator.
/**
 * @link ../../../../.docs/features/mobile/a-purpose-string-in-every-language.md
 */

return [
    'en' => [
        'NSCameraUsageDescription' => 'Beatrax uses the camera to scan the pairing code shown on your other device. Nothing is photographed or stored.',
        'NSFaceIDUsageDescription' => 'Beatrax uses Face ID to unlock your finances and release the key your data is encrypted with.',
        'NSLocalNetworkUsageDescription' => 'Beatrax uses your local network to sync your finances directly with your other Beatrax devices — nothing ever leaves your home network for this.',
    ],

    'bg' => [
        'NSCameraUsageDescription' => 'Beatrax използва камерата, за да сканира кода за сдвояване, показан на другото ти устройство. Нищо не се снима и не се запазва.',
        'NSFaceIDUsageDescription' => 'Beatrax използва Face ID, за да отключи финансите ти и да освободи ключа, с който данните ти са шифровани.',
        'NSLocalNetworkUsageDescription' => 'Beatrax използва локалната ти мрежа, за да синхронизира финансите ти директно с другите ти устройства с Beatrax — за това нищо не напуска домашната ти мрежа.',
    ],

    'cs' => [
        'NSCameraUsageDescription' => 'Beatrax používá fotoaparát ke skenování párovacího kódu zobrazeného na tvém druhém zařízení. Nic se nefotí ani neukládá.',
        'NSFaceIDUsageDescription' => 'Beatrax používá Face ID, aby odemkl tvoje finance a uvolnil klíč, kterým jsou tvoje data zašifrovaná.',
        'NSLocalNetworkUsageDescription' => 'Beatrax používá tvoji místní síť, aby synchronizoval tvoje finance přímo s tvými dalšími zařízeními s Beatraxem — kvůli tomu nic neopouští tvoji domácí síť.',
    ],

    'da' => [
        'NSCameraUsageDescription' => 'Beatrax bruger kameraet til at scanne parringskoden, der vises på din anden enhed. Der bliver hverken taget eller gemt billeder.',
        'NSFaceIDUsageDescription' => 'Beatrax bruger Face ID til at låse din økonomi op og frigive nøglen, dine data er krypteret med.',
        'NSLocalNetworkUsageDescription' => 'Beatrax bruger dit lokale netværk til at synkronisere din økonomi direkte med dine andre Beatrax-enheder — intet forlader dit hjemmenetværk for at gøre det.',
    ],

    'de' => [
        'NSCameraUsageDescription' => 'Beatrax nutzt die Kamera, um den Kopplungscode zu scannen, der auf deinem anderen Gerät angezeigt wird. Es wird nichts fotografiert oder gespeichert.',
        'NSFaceIDUsageDescription' => 'Beatrax nutzt Face ID, um deine Finanzen zu entsperren und den Schlüssel freizugeben, mit dem deine Daten verschlüsselt sind.',
        'NSLocalNetworkUsageDescription' => 'Beatrax nutzt dein lokales Netzwerk, um deine Finanzen direkt mit deinen anderen Beatrax-Geräten zu synchronisieren — dafür verlässt nichts dein Heimnetzwerk.',
    ],

    'el' => [
        'NSCameraUsageDescription' => 'Το Beatrax χρησιμοποιεί την κάμερα για να σαρώσει τον κωδικό σύζευξης που εμφανίζεται στην άλλη σου συσκευή. Τίποτα δεν φωτογραφίζεται ούτε αποθηκεύεται.',
        'NSFaceIDUsageDescription' => 'Το Beatrax χρησιμοποιεί το Face ID για να ξεκλειδώσει τα οικονομικά σου και να απελευθερώσει το κλειδί με το οποίο είναι κρυπτογραφημένα τα δεδομένα σου.',
        'NSLocalNetworkUsageDescription' => 'Το Beatrax χρησιμοποιεί το τοπικό σου δίκτυο για να συγχρονίζει τα οικονομικά σου απευθείας με τις άλλες σου συσκευές Beatrax — τίποτα δεν βγαίνει από το οικιακό σου δίκτυο γι᾽ αυτό.',
    ],

    'es' => [
        'NSCameraUsageDescription' => 'Beatrax usa la cámara para escanear el código de vinculación que aparece en tu otro dispositivo. No se fotografía ni se guarda nada.',
        'NSFaceIDUsageDescription' => 'Beatrax usa Face ID para desbloquear tus finanzas y liberar la clave con la que están cifrados tus datos.',
        'NSLocalNetworkUsageDescription' => 'Beatrax usa tu red local para sincronizar tus finanzas directamente con tus otros dispositivos Beatrax; para esto nada sale de tu red doméstica.',
    ],

    'et' => [
        'NSCameraUsageDescription' => 'Beatrax kasutab kaamerat, et skannida sidumiskoodi, mida kuvatakse su teises seadmes. Midagi ei pildistata ega salvestata.',
        'NSFaceIDUsageDescription' => 'Beatrax kasutab Face ID-d, et su rahaasjad avada ja vabastada võti, millega su andmed on krüpteeritud.',
        'NSLocalNetworkUsageDescription' => 'Beatrax kasutab sinu kohtvõrku, et sünkroonida su rahaasjad otse su teiste Beatraxi seadmetega — selleks ei lahku miski su koduvõrgust.',
    ],

    'fi' => [
        'NSCameraUsageDescription' => 'Beatrax käyttää kameraa lukeakseen laiteparin koodin, joka näkyy toisessa laitteessasi. Mitään ei valokuvata eikä tallenneta.',
        'NSFaceIDUsageDescription' => 'Beatrax käyttää Face ID:tä avatakseen taloutesi ja vapauttaakseen avaimen, jolla tietosi on salattu.',
        'NSLocalNetworkUsageDescription' => 'Beatrax käyttää lähiverkkoasi synkronoidakseen taloutesi suoraan muiden Beatrax-laitteidesi kanssa — mikään ei poistu kotiverkostasi tätä varten.',
    ],

    'fr' => [
        'NSCameraUsageDescription' => 'Beatrax utilise l’appareil photo pour scanner le code d’appairage affiché sur ton autre appareil. Rien n’est photographié ni conservé.',
        'NSFaceIDUsageDescription' => 'Beatrax utilise Face ID pour déverrouiller tes finances et libérer la clé avec laquelle tes données sont chiffrées.',
        'NSLocalNetworkUsageDescription' => 'Beatrax utilise ton réseau local pour synchroniser tes finances directement avec tes autres appareils Beatrax — rien ne quitte ton réseau domestique pour cela.',
    ],

    'hr' => [
        'NSCameraUsageDescription' => 'Beatrax koristi kameru za skeniranje koda za uparivanje prikazanog na tvom drugom uređaju. Ništa se ne fotografira niti pohranjuje.',
        'NSFaceIDUsageDescription' => 'Beatrax koristi Face ID za otključavanje tvojih financija i otpuštanje ključa kojim su tvoji podaci šifrirani.',
        'NSLocalNetworkUsageDescription' => 'Beatrax koristi tvoju lokalnu mrežu kako bi tvoje financije sinkronizirao izravno s tvojim drugim Beatrax uređajima — za to ništa ne napušta tvoju kućnu mrežu.',
    ],

    'hu' => [
        'NSCameraUsageDescription' => 'A Beatrax a kamerát a másik eszközödön megjelenő párosítási kód beolvasására használja. Semmiről nem készül fénykép, és semmi nem kerül mentésre.',
        'NSFaceIDUsageDescription' => 'A Beatrax a Face ID-t a pénzügyeid feloldására és annak a kulcsnak a kiadására használja, amellyel az adataid titkosítva vannak.',
        'NSLocalNetworkUsageDescription' => 'A Beatrax a helyi hálózatodat használja, hogy a pénzügyeidet közvetlenül a többi Beatrax-eszközöddel szinkronizálja — ehhez semmi nem hagyja el az otthoni hálózatodat.',
    ],

    'it' => [
        'NSCameraUsageDescription' => 'Beatrax usa la fotocamera per scansionare il codice di abbinamento mostrato sull’altro tuo dispositivo. Non viene fotografato né salvato nulla.',
        'NSFaceIDUsageDescription' => 'Beatrax usa Face ID per sbloccare le tue finanze e rilasciare la chiave con cui i tuoi dati sono cifrati.',
        'NSLocalNetworkUsageDescription' => 'Beatrax usa la tua rete locale per sincronizzare le tue finanze direttamente con i tuoi altri dispositivi Beatrax: per questo nulla esce dalla tua rete domestica.',
    ],

    'lt' => [
        'NSCameraUsageDescription' => '„Beatrax“ naudoja kamerą, kad nuskaitytų susiejimo kodą, rodomą kitame tavo įrenginyje. Niekas nefotografuojama ir neįrašoma.',
        'NSFaceIDUsageDescription' => '„Beatrax“ naudoja „Face ID“, kad atrakintų tavo finansus ir atlaisvintų raktą, kuriuo užšifruoti tavo duomenys.',
        'NSLocalNetworkUsageDescription' => '„Beatrax“ naudoja tavo vietinį tinklą, kad tavo finansus sinchronizuotų tiesiai su kitais tavo „Beatrax“ įrenginiais — tam niekas neišeina iš tavo namų tinklo.',
    ],

    'lv' => [
        'NSCameraUsageDescription' => 'Beatrax izmanto kameru, lai noskenētu savienošanas kodu, kas redzams jūsu otrā ierīcē. Nekas netiek fotografēts vai saglabāts.',
        'NSFaceIDUsageDescription' => 'Beatrax izmanto Face ID, lai atbloķētu jūsu finanses un atbrīvotu atslēgu, ar kuru ir šifrēti jūsu dati.',
        'NSLocalNetworkUsageDescription' => 'Beatrax izmanto jūsu lokālo tīklu, lai sinhronizētu jūsu finanses tieši ar jūsu pārējām Beatrax ierīcēm — nekas šim nolūkam neatstāj jūsu mājas tīklu.',
    ],

    'nb' => [
        'NSCameraUsageDescription' => 'Beatrax bruker kameraet til å skanne paringskoden som vises på den andre enheten din. Ingenting fotograferes eller lagres.',
        'NSFaceIDUsageDescription' => 'Beatrax bruker Face ID til å låse opp økonomien din og frigi nøkkelen dataene dine er kryptert med.',
        'NSLocalNetworkUsageDescription' => 'Beatrax bruker det lokale nettverket ditt til å synkronisere økonomien din direkte med de andre Beatrax-enhetene dine — ingenting forlater hjemmenettverket ditt for dette.',
    ],

    'nl' => [
        'NSCameraUsageDescription' => 'Beatrax gebruikt de camera om de koppelcode te scannen die op je andere apparaat staat. Er wordt niets gefotografeerd of bewaard.',
        'NSFaceIDUsageDescription' => 'Beatrax gebruikt Face ID om je financiën te ontgrendelen en de sleutel vrij te geven waarmee je gegevens versleuteld zijn.',
        'NSLocalNetworkUsageDescription' => 'Beatrax gebruikt je lokale netwerk om je financiën rechtstreeks met je andere Beatrax-apparaten te synchroniseren — hiervoor verlaat er niets je thuisnetwerk.',
    ],

    'pl' => [
        'NSCameraUsageDescription' => 'Beatrax używa aparatu do zeskanowania kodu parowania wyświetlonego na Twoim drugim urządzeniu. Nic nie jest fotografowane ani zapisywane.',
        'NSFaceIDUsageDescription' => 'Beatrax używa Face ID, aby odblokować Twoje finanse i udostępnić klucz, którym zaszyfrowane są Twoje dane.',
        'NSLocalNetworkUsageDescription' => 'Beatrax używa Twojej sieci lokalnej, aby synchronizować Twoje finanse bezpośrednio z Twoimi innymi urządzeniami Beatrax — nic nie opuszcza w tym celu Twojej sieci domowej.',
    ],

    'pt' => [
        'NSCameraUsageDescription' => 'O Beatrax usa a câmara para ler o código de emparelhamento mostrado no teu outro dispositivo. Nada é fotografado nem guardado.',
        'NSFaceIDUsageDescription' => 'O Beatrax usa o Face ID para desbloquear as tuas finanças e libertar a chave com que os teus dados estão cifrados.',
        'NSLocalNetworkUsageDescription' => 'O Beatrax usa a tua rede local para sincronizar as tuas finanças diretamente com os teus outros dispositivos Beatrax — nada sai da tua rede doméstica para isso.',
    ],

    'ro' => [
        'NSCameraUsageDescription' => 'Beatrax folosește camera pentru a scana codul de asociere afișat pe celălalt dispozitiv al tău. Nimic nu este fotografiat sau salvat.',
        'NSFaceIDUsageDescription' => 'Beatrax folosește Face ID pentru a-ți debloca finanțele și a elibera cheia cu care îți sunt criptate datele.',
        'NSLocalNetworkUsageDescription' => 'Beatrax folosește rețeaua ta locală pentru a-ți sincroniza finanțele direct cu celelalte dispozitive Beatrax ale tale — nimic nu îți părăsește rețeaua de acasă pentru asta.',
    ],

    'sk' => [
        'NSCameraUsageDescription' => 'Beatrax používa fotoaparát na naskenovanie párovacieho kódu zobrazeného na tvojom druhom zariadení. Nič sa nefotí ani neukladá.',
        'NSFaceIDUsageDescription' => 'Beatrax používa Face ID, aby odomkol tvoje financie a uvoľnil kľúč, ktorým sú tvoje dáta zašifrované.',
        'NSLocalNetworkUsageDescription' => 'Beatrax používa tvoju lokálnu sieť, aby tvoje financie synchronizoval priamo s tvojimi ďalšími zariadeniami s Beatraxom — kvôli tomu nič neopúšťa tvoju domácu sieť.',
    ],

    'sl' => [
        'NSCameraUsageDescription' => 'Beatrax uporablja kamero za skeniranje kode za seznanitev, prikazane na tvoji drugi napravi. Nič se ne fotografira in nič se ne shrani.',
        'NSFaceIDUsageDescription' => 'Beatrax uporablja Face ID, da odklene tvoje finance in sprosti ključ, s katerim so tvoji podatki šifrirani.',
        'NSLocalNetworkUsageDescription' => 'Beatrax uporablja tvoje lokalno omrežje, da tvoje finance sinhronizira neposredno s tvojimi drugimi napravami Beatrax — za to nič ne zapusti tvojega domačega omrežja.',
    ],

    'sr' => [
        'NSCameraUsageDescription' => 'Beatrax koristi kameru da skenira kôd za uparivanje prikazan na tvom drugom uređaju. Ništa se ne fotografiše niti čuva.',
        'NSFaceIDUsageDescription' => 'Beatrax koristi Face ID da otključa tvoje finansije i oslobodi ključ kojim su tvoji podaci šifrovani.',
        'NSLocalNetworkUsageDescription' => 'Beatrax koristi tvoju lokalnu mrežu da tvoje finansije sinhronizuje direktno sa tvojim drugim Beatrax uređajima — za to ništa ne napušta tvoju kućnu mrežu.',
    ],

    'sv' => [
        'NSCameraUsageDescription' => 'Beatrax använder kameran för att läsa av parkopplingskoden som visas på din andra enhet. Ingenting fotograferas eller sparas.',
        'NSFaceIDUsageDescription' => 'Beatrax använder Face ID för att låsa upp din ekonomi och frigöra nyckeln som dina uppgifter är krypterade med.',
        'NSLocalNetworkUsageDescription' => 'Beatrax använder ditt lokala nätverk för att synkronisera din ekonomi direkt med dina andra Beatrax-enheter — ingenting lämnar ditt hemnätverk för det.',
    ],

    'tr' => [
        'NSCameraUsageDescription' => 'Beatrax, diğer cihazında görünen eşleştirme kodunu taramak için kamerayı kullanır. Hiçbir şey fotoğraflanmaz veya saklanmaz.',
        'NSFaceIDUsageDescription' => 'Beatrax, finanslarının kilidini açmak ve verilerinin şifrelendiği anahtarı serbest bırakmak için Face ID’yi kullanır.',
        'NSLocalNetworkUsageDescription' => 'Beatrax, finanslarını doğrudan diğer Beatrax cihazlarınla eşitlemek için yerel ağını kullanır; bunun için hiçbir şey ev ağından çıkmaz.',
    ],

    'uk' => [
        'NSCameraUsageDescription' => 'Beatrax використовує камеру, щоб відсканувати код сполучення, показаний на твоєму іншому пристрої. Нічого не фотографується й не зберігається.',
        'NSFaceIDUsageDescription' => 'Beatrax використовує Face ID, щоб розблокувати твої фінанси й вивільнити ключ, яким зашифровані твої дані.',
        'NSLocalNetworkUsageDescription' => 'Beatrax використовує твою локальну мережу, щоб синхронізувати твої фінанси напряму з іншими твоїми пристроями Beatrax — для цього нічого не залишає твою домашню мережу.',
    ],
];
