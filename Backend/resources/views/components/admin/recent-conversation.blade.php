<div class="bg-white rounded-lg shadow p-5">

    <div class="flex items-center justify-between mb-4">

        <h3 class="font-bold text-lg">
            Recent Conversations
        </h3>

        @if(Route::has('conversations.index'))
            <a href="{{ route('conversations.index') }}"
               class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                View All
            </a>
        @endif

    </div>



<div class="max-h-[180px] overflow-y-auto pr-2 space-y-4">

    @forelse($conversations as $conversation)

        @php
            $lastMessage = $conversation->messages->sortByDesc('created_at')->first();
        @endphp

        <div class="border rounded-lg p-4 hover:bg-gray-50 transition">

            <div class="flex items-start justify-between">

                <div>
                    <div class="font-semibold text-gray-900">
                        {{ $conversation->customer->name }}
                    </div>

                    <div class="text-xs text-gray-500">
                        {{ $conversation->customer->phone }}
                    </div>
                </div>

                @if($conversation->status === 'open')
                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">
                        Open
                    </span>
                @elseif($conversation->status === 'closed')
                    <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">
                        Closed
                    </span>
                @else
                    <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">
                        Handover
                    </span>
                @endif

            </div>


            <div class="mt-3 text-sm text-gray-700">

                @if($lastMessage)
                    {{ \Illuminate\Support\Str::limit($lastMessage->message, 70) }}
                @else
                    <span class="text-gray-400">No messages yet</span>
                @endif

            </div>


            <div class="mt-3 flex items-center justify-between text-xs text-gray-500">

                <span>
                    {{ ($conversation->started_at ?? $conversation->created_at)->diffForHumans() }}
                </span>

                @if($lastMessage)
                    <span>
                        {{ $lastMessage->created_at->diffForHumans() }}
                    </span>
                @endif

            </div>

        </div>

    @empty

        <div class="text-center py-8 text-gray-500">
            No conversations found.
        </div>

    @endforelse

</div>

</div>