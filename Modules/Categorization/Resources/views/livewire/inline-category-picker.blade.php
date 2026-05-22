<div>
    <select
        wire:model.live="categoryId"
        class="block w-full rounded-md border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
        aria-label="Category"
    >
        <option value="">—</option>
        @foreach ($categories as $cat)
            <option value="{{ $cat->id }}">{{ $cat->path }}</option>
        @endforeach
    </select>
</div>
