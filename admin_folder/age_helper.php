
<?php

function calculateBabyAge($birthday) {

    if (empty($birthday)) {
        return "";
    }

    $birthDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $birthday
    );

    $today = new DateTimeImmutable("today");

    if (
        !$birthDate ||
        $birthDate->format("Y-m-d") !== $birthday ||
        $birthDate > $today
    ) {
        return "";
    }

    $difference = $birthDate->diff($today);

    if ($difference->y >= 1) {

        $years = $difference->y;

        return $years .
            ($years === 1 ? " year" : " years");
    }

    $months = $difference->m;

    if ($months >= 1) {

        return $months .
            ($months === 1 ? " month" : " months");
    }

    $days = (int) $difference->days;

    if ($days >= 7) {

        $weeks = intdiv($days, 7);

        return $weeks .
            ($weeks === 1 ? " week" : " weeks");
    }

    return $days .
        ($days === 1 ? " day" : " days");
}
