<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Infracare AI Dashboard
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Monitoring customer conversation, AI performance, and knowledge base
                </p>
            </div>


        <div class="text-sm text-gray-500">
            Last updated: {{ now()->format('d M Y H:i') }}
        </div>
    </div>
</x-slot>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- STAT CARDS --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">

            <x-admin.stat-card
                title="Total Customer"
                :value="$totalCustomers"
                icon="👥"
            />

            <x-admin.stat-card
                title="Conversations"
                :value="$totalConversations"
                icon="💬"
            />

            <x-admin.stat-card
                title="Messages"
                :value="$totalMessages"
                icon="📩"
            />

            <x-admin.stat-card
                title="AI Success Rate"
                :value="$aiSuccessRate . '%'"
                icon="🤖"
            />

        </div>


        {{-- MAIN GRID --}}
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

            {{-- LEFT SIDE --}}
            <div class="xl:col-span-2 space-y-6">

                <x-admin.activity-chart
                    :activities="$chatActivity"
                />

                <x-admin.recent-conversation
                    :conversations="$recentConversations"
                />

            </div>



            {{-- RIGHT SIDE --}}
            <div class="space-y-6">

                <x-admin.ai-performance
                    :rate="$aiSuccessRate"
                />

                <x-admin.conversation-status
                    :statuses="$conversationStatus"
                />

                <x-admin.recent-customer
                    :customers="$recentCustomers"
                />

            </div>

        </div>

    </div>
</div>

</x-app-layout>
