{{-- Visible left-sidebar logout. Filament/Laravel logout is a CSRF-protected
     POST, so this is a real <form> posting to the panel's logout route — not a
     GET link. Pinned to the bottom of the sidebar, separated by a top border. --}}
<div class="fi-sidebar-logout mt-auto border-t border-gray-200 pt-2 dark:border-white/10">
    <form method="POST" action="{{ filament()->getLogoutUrl() }}">
        @csrf

        <button
            type="submit"
            class="fi-sidebar-item-button flex w-full items-center gap-x-3 rounded-lg px-2 py-2 text-sm font-medium text-gray-700 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        >
            @svg('heroicon-o-arrow-left-on-rectangle', 'fi-sidebar-item-icon h-6 w-6 text-gray-400 dark:text-gray-500')

            <span class="fi-sidebar-item-label flex-1 truncate text-start">
                Logout
            </span>
        </button>
    </form>
</div>
