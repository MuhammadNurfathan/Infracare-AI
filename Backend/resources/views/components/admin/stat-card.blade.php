<div class="bg-white rounded-lg shadow p-5">

    <div class="flex justify-between items-center">

        <div>

            <p class="text-gray-500 text-sm">
                {{ $title }}
            </p>


            <h2 class="text-3xl font-bold mt-2">
                {{ $value }}
            </h2>

        </div>


        @if($icon)

        <div class="text-3xl">
            {{ $icon }}
        </div>

        @endif


    </div>

</div>