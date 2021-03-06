<?php

namespace Tests\Unit;

use BikeShare\Domain\Bike\Bike;
use BikeShare\Domain\Bike\BikeStatus;
use BikeShare\Domain\Rent\Rent;
use BikeShare\Domain\Rent\RentStatus;
use BikeShare\Domain\Stand\Stand;
use BikeShare\Domain\User\User;
use BikeShare\Http\Services\AppConfig;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotFreeException;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotOnTopException;
use BikeShare\Http\Services\Rents\Exceptions\LowCreditException;
use BikeShare\Http\Services\Rents\Exceptions\MaxNumberOfRentsException;
use BikeShare\Http\Services\Rents\RentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class RentBikeTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @var RentService
     */
    private $rentService;

    /**
     * @var AppConfig
     */
    private $appConfig;

    protected function setUp()
    {
        parent::setUp();
        $this->rentService = app(RentService::class);
        $this->appConfig = app(AppConfig::class);
    }

    /** @test */
    public function rent_bike_low_credit()
    {
        // Arrange
        $lowCredit = $this->appConfig->getRequiredCredit() - 1;
        $user = create(User::class, ['credit' => $lowCredit]);
        $bike = create(Stand::class)->bikes()->save(make(Bike::class));

        // Assert
        $this->expectException(LowCreditException::class);

        // Act
        $this->rentService->rentBike($user, $bike);
    }

    /** @test */
    public function rent_bike_which_is_already_rented_by_me()
    {
        // Arrange
        $user = userWithResources();
        $bike = create(Stand::class)->bikes()->save(make(Bike::class));
        $this->rentService->rentBike($user, $bike);

        // Assert
        $this->expectException(BikeNotFreeException::class);

        // Act
        $this->rentService->rentBike($user, $bike);
    }

    /** @test */
    public function rent_bike_already_rented_by_other_user()
    {
        // Arrange
        $myUser = userWithResources();
        $otherUser = userWithResources();
        $bike = create(Stand::class)->bikes()->save(make(Bike::class));
        $this->rentService->rentBike($otherUser, $bike);

        // Assert
        $this->expectException(BikeNotFreeException::class);

        // Act
        $this->rentService->rentBike($myUser, $bike);
    }

    /** @test */
    public function rent_bike_with_zero_user_limit()
    {
        // Arrange
        $myUser = userWithResources(['limit' => 0]);
        $bike = create(Stand::class)->bikes()->save(make(Bike::class));

        // Assert
        $this->expectException(MaxNumberOfRentsException::class);

        // Act
        $this->rentService->rentBike($myUser, $bike);
    }

    /** @test */
    public function rent_bike_not_from_top_with_top_stack_enforced()
    {
        // Arrange
        $user = userWithResources();
        $stand = create(Stand::class);
        $bikeNotOnTop = $stand->bikes()->save(make(Bike::class, ['stack_position' => 0]));
        $bikeOnTop = $stand->bikes()->save(make(Bike::class, ['stack_position' => 1]));
        config(['bike-share.stack_bike' => true]);

        // Assert
        self::assertTrue($this->appConfig->isStackBikeEnabled());
        $this->expectException(BikeNotOnTopException::class);

        // Act
        $this->rentService->rentBike($user, $bikeNotOnTop);
    }

    /** @test */
    public function rent_bike_ok()
    {
        // Arrange
        $user = userWithResources();
        $bike = create(Stand::class)->bikes()->save(make(Bike::class));

        // Act
        $rent = $this->rentService->rentBike($user, $bike);
        $bike->fresh();

        // Assert
        self::assertEquals($bike->status, BikeStatus::OCCUPIED);
        self::assertNull($bike->stand);
        self::assertEquals($bike->user->id, $user->id);

        $associatedRent = Rent::where(['bike_id'=>$bike->id, 'user_id'=>$user->id, 'status' => RentStatus::OPEN])->first();
        self::assertNotNull($associatedRent);
        self::assertEquals($associatedRent->id, $rent->id);
    }

    /** @test */
    public function rent_bike_ok_with_stack_enforced()
    {
        // Arrange
        $user = userWithResources();
        $stand = create(Stand::class);
        $bikeNotOnTop = $stand->bikes()->save(make(Bike::class, ['stack_position'=>0]));
        $bikeOnTop = $stand->bikes()->save(make(Bike::class, ['stack_position'=>1]));
        config(['bike-share.stack_bike' => true]);

        // Act
        $rent = $this->rentService->rentBike($user, $bikeOnTop);

        // Assert
        self::assertEquals($rent->bike->id, $bikeOnTop->id);
    }
}
