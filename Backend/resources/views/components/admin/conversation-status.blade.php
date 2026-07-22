<div class="bg-white rounded-lg shadow p-5">

    <h3 class="font-bold text-lg mb-4">
        Conversation Status
    </h3>


    @foreach($statuses as $status => $total)

        <div class="flex justify-between items-center border-b py-3">

            <span class="capitalize">
                {{ $status }}
            </span>


            <span class="font-bold">
                {{ $total }}
            </span>


        </div>

    @endforeach


</div>