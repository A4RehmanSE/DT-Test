<?php

namespace Tests;

use Carbon\Carbon;
use DTApi\Helpers\TeHelper;
use PHPUnit\Framework\TestCase;

class TeHelperTest extends TestCase
{
    public function testWillExpireAt()
    {
        // Set the timezone to match the expected output
        date_default_timezone_set('UTC');

        // Create Carbon instances for the due time and created at time
        $dueTime = Carbon::parse('2023-06-01 12:00:00');
        $createdAt = Carbon::parse('2023-05-31 10:00:00');

        // Call the willExpireAt method
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        // Calculate the expected expiration time
        $expectedExpirationTime = $dueTime->subHours(48)->format('Y-m-d H:i:s');

        // Assert that the result matches the expected expiration time
        $this->assertEquals($expectedExpirationTime, $result);
    }
}
