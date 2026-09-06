@php($isActive = request()->routeIs($pattern))
<a href="{{ route($route) }}" class="flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 font-medium leading-snug transition {{ $isActive ? 'bg-orange-500 text-white shadow-lg shadow-orange-950/20 ring-1 ring-inset ring-orange-300/30' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}" @if($isActive) aria-current="page" data-nav-active @endif>
    <span class="w-5 shrink-0 text-center text-base" aria-hidden="true">{{ $icon }}</span><span class="min-w-0">{{ $label }}</span>
</a>
