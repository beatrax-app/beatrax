<?php

declare(strict_types=1);

return [
    'heading' => 'Sugerir um mapeamento',
    'intro' => 'Abre o GitHub no teu navegador com a sugestão já preenchida. Só seguem com ela o padrão, o nome, a categoria e a região acima — e o padrão é a descrição tal como o teu extrato a escreveu. O teu nome e o teu e-mail nunca saem deste dispositivo.',

    'pattern' => 'Padrão',
    'name' => 'Nome legível',
    'name_placeholder' => 'ex.: Albert Heijn',
    'category' => 'Categoria (opcional)',
    'category_placeholder' => 'ex.: Supermercado',
    'region' => 'Região',

    'regions' => [
        'other' => 'Outra',
    ],

    'yaml_preview' => 'Pré-visualização do YAML',

    'cancel' => 'Cancelar',
    'submit' => 'Abrir no GitHub',

    'toast' => 'Sugestão aberta no teu navegador.',

    'errors' => [
        'pattern_required' => 'O padrão é obrigatório.',
        'name_required' => 'O nome é obrigatório.',
        'browser_refused' => 'Não foi possível abrir o teu navegador, por isso nada foi enviado e nada saiu deste dispositivo. Tenta outra vez ou copia tu mesmo a pré-visualização YAML acima para um pull request.',
    ],
];
