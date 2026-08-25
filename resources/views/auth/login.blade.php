<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ingresar · Pizzería</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen bg-[#201a17] text-stone-900">
<main class="grid min-h-screen lg:grid-cols-2">
    <section class="hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-orange-500 to-red-600 p-12 text-white lg:flex">
        <div class="text-2xl font-semibold">🍕 Mi Pizzería</div>
        <div class="max-w-xl"><p class="text-sm font-semibold uppercase tracking-[.25em] text-orange-100">Todo en su lugar</p><h1 class="mt-4 text-5xl font-semibold leading-tight">Ingredientes frescos.<br>Control claro.</h1><p class="mt-6 text-lg text-orange-50">Gestiona catálogo, recetas, inventario y compras desde un solo espacio diseñado para el ritmo de una pizzería.</p></div>
        <p class="text-sm text-orange-100">Sistema local · Acceso seguro</p>
    </section>
    <section class="grid place-items-center p-6 sm:p-10">
        <div class="w-full max-w-md rounded-3xl bg-white p-7 shadow-2xl sm:p-10">
            <div class="mb-8"><div class="mb-5 grid size-12 place-items-center rounded-2xl bg-orange-100 text-2xl lg:hidden">🍕</div><p class="text-sm font-semibold text-orange-600">Bienvenido</p><h2 class="mt-2 text-3xl font-semibold">Ingresa a tu pizzería</h2><p class="mt-2 text-sm text-stone-500">Usa las credenciales asignadas a tu cuenta.</p></div>
            @if ($errors->any())<div class="mb-5 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">@csrf
                <div><label class="label" for="email">Correo electrónico</label><input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"></div>
                <div><label class="label" for="password">Contraseña</label><input class="input" id="password" name="password" type="password" required autocomplete="current-password"></div>
                <label class="flex items-center gap-2 text-sm text-stone-600"><input type="checkbox" name="remember" value="1" class="size-4 rounded border-stone-300 text-orange-600"> Mantener mi sesión</label>
                <button class="btn-primary w-full" type="submit">Ingresar</button>
            </form>
        </div>
    </section>
</main>
</body></html>
