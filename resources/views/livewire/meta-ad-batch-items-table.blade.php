<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
            {{ $items->total() }} item(ns)
        </p>

        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <span>Por pagina</span>
            <select
                wire:model.live="perPage"
                class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
            >
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
            </select>
        </label>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                    <tr>
                        <th class="px-4 py-3">Cidade</th>
                        <th class="px-4 py-3">Fonte</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Erro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($items as $item)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $item->city_name }}
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                                @if (filled($item->creative_source_path))
                                    #{{ ((int) ($item->creative_source_index ?? 0)) + 1 }} - {{ basename($item->creative_source_path) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-200">
                                {{ $item->status }}
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                                {{ filled($item->error_message) ? $item->error_message : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400">
                                Nenhum item encontrado para este lote.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        {{ $items->links() }}
    </div>
</div>
