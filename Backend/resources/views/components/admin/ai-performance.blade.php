<div class="bg-white rounded-lg shadow p-5">

    <h3 class="font-bold text-lg mb-4">
        AI Performance
    </h3>


    <div class="flex items-center justify-between">


        <div>

            <p class="text-gray-500">
                Success Rate
            </p>


            <h2 class="text-4xl font-bold mt-2">
                {{ $rate }}%
            </h2>

        </div>



        <div>

            @if($rate >= 80)

                <span class="px-3 py-2 rounded bg-green-100 text-green-700">
                    Excellent
                </span>


            @elseif($rate >= 60)

                <span class="px-3 py-2 rounded bg-yellow-100 text-yellow-700">
                    Good
                </span>


            @else

                <span class="px-3 py-2 rounded bg-red-100 text-red-700">
                    Need Improvement
                </span>


            @endif


        </div>


    </div>


</div>