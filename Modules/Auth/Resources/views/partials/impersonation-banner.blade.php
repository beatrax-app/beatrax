<div role="alert"
     class="border-b border-amber-300 bg-amber-50 px-6 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200 dark:border-amber-800">
    <div class="mx-auto flex max-w-5xl items-center justify-between">
        <p>
            Acting as <strong>{{ $username }}</strong> &mdash;
            <a href="#"
               x-data
               @click.prevent="$refs.endImpersonation.submit()"
               class="underline underline-offset-2 hover:text-amber-950 focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2">Return to self</a>
        </p>
        <form x-ref="endImpersonation" method="POST" action="{{ route('auth.impersonate.end') }}" class="hidden">
            @csrf
        </form>
    </div>
</div>
