<div class="bg-white rounded-lg shadow p-5">

    <div class="flex items-center justify-between mb-4">

        <h3 class="font-bold text-lg">
            Recent Customers
        </h3>

        <a href="{{ route('customers.index') }}"
           class="text-blue-600 hover:text-blue-700 text-sm font-medium">
            View All
        </a>

    </div>


    <div class="overflow-x-auto max-h-[500px] overflow-y-auto pr-2">

        <table class="min-w-full text-sm">

            <thead class="sticky top-0 bg-white z-10">
                <tr class="border-b text-gray-500">
                    <th class="text-left py-3">Customer</th>
                    <th class="text-left py-3">Phone</th>
                    <th class="text-left py-3">Last Chat</th>
                </tr>
            </thead>

            <tbody>

                @forelse($customers as $customer)

                    <tr class="border-b hover:bg-gray-50">

                        <td class="py-3">

                            <div class="font-semibold text-gray-900">
                                {{ $customer->name }}
                            </div>

                            @if($customer->wa_name)
                                <div class="text-xs text-gray-500">
                                    {{ $customer->wa_name }}
                                </div>
                            @endif

                        </td>


                        <td class="py-3 text-gray-700">
                            {{ $customer->phone }}
                        </td>


                        <td class="py-3 text-gray-700">

                            @if($customer->last_chat_at)
                                {{ \Carbon\Carbon::parse($customer->last_chat_at)->diffForHumans() }}
                            @else
                                <span class="text-gray-400">No chat yet</span>
                            @endif

                        </td>

                    </tr>

                @empty

                    <tr>
                        <td colspan="3" class="text-center py-6 text-gray-500">
                            No customers found.
                        </td>
                    </tr>

                @endforelse

            </tbody>

        </table>

    </div>

</div>