<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modo de demostración
    |--------------------------------------------------------------------------
    |
    | Mientras permanezca activo, el servidor valida el carrito y los precios,
    | pero nunca construye ni devuelve enlaces de WhatsApp para enviar pedidos.
    |
    */
    'demo_mode' => true,

    /*
    |--------------------------------------------------------------------------
    | Contexto inicial de la tienda pública
    |--------------------------------------------------------------------------
    |
    | La primera tienda usa los registros existentes de Masa & Maña. Cuando
    | existan dominios propios, cada host podrá apuntar a otra empresa/sucursal
    | sin cambiar las rutas ni confiar en identificadores enviados por el cliente.
    |
    */
    'default' => [
        'company_id' => 1,
        'branch_id' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Información pública de atención
    |--------------------------------------------------------------------------
    |
    | Estos valores permanecen vacíos hasta que el restaurante confirme sus
    | horarios, enlace de mapa y condiciones de delivery. También pueden
    | sobrescribirse dentro de la entrada de un dominio en `domains`.
    |
    */
    'location' => [
        'hours' => [],
        'map_url' => null,
        'delivery_note' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo visible en la tienda
    |--------------------------------------------------------------------------
    |
    | La lista de categorías es obligatoria y falla de forma cerrada cuando
    | queda vacía. La lista de productos es opcional: vacía permite todos
    | los productos activos dentro de las categorías aprobadas.
    |
    */
    'visibility' => [
        'category_names' => [
            'Pizzas con maña',
            'Las de siempre - con maña',
            'Gaseosas',
            'Jugos naturales',
            'Bebidas alcohólicas',
        ],
        'category_ulids' => [],
        'product_names' => [],
        'product_ulids' => [],
    ],

    'domains' => [
        // 'pedidos.ejemplo.com' => [
        //     'company_id' => 2,
        //     'branch_id' => 4,
        //     'location' => ['hours' => [], 'map_url' => null, 'delivery_note' => null],
        // ],
    ],
];
