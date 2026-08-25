<a href="{{ route($route) }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-medium transition {{ request()->routeIs($pattern) ? 'bg-orange-500 text-white shadow-lg shadow-orange-950/20' : 'text-stone-300 hover:bg-white/5 hover:text-white' }}">
    <span class="w-5 text-center text-base">{{ $icon }}</span><span>{{ $label }}</span>
</a>
