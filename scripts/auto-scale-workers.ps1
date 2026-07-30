param(
    [int]$ScaleUpThreshold = 100,
    [int]$ScaleDownThreshold = 10,
    [int]$MinWorkers = 1,
    [int]$MaxWorkers = 5
)

$ErrorActionPreference = 'Stop'

function Get-QueueSize {
    param(
        [string]$QueueName
    )

    $result = docker compose exec -T rabbitmq `
        rabbitmqctl list_queues name messages `
        --formatter json |
        ConvertFrom-Json

    $queue = $result |
        Where-Object { $_.name -eq $QueueName }

    if ($null -eq $queue) {
        return 0
    }

    return [int]$queue.messages
}

function Get-WorkerCount {
    param(
        [string]$ServiceName
    )

    $containerIds = docker compose ps `
        --quiet `
        $ServiceName

    if ([string]::IsNullOrWhiteSpace($containerIds)) {
        return 0
    }

    return @($containerIds).Count
}

function Set-WorkerScale {
    param(
        [string]$ServiceName,
        [int]$Replicas
    )

    Write-Host "Scaling $ServiceName to $Replicas instance(s)..."

    docker compose up -d `
        --scale "$ServiceName=$Replicas" `
        --no-recreate
}

function Update-WorkerScale {
    param(
        [string]$ServiceName,
        [string]$QueueName
    )

    $queueSize = Get-QueueSize `
        -QueueName $QueueName

    $workerCount = Get-WorkerCount `
        -ServiceName $ServiceName

    Write-Host (
        "{0}: queue={1}, workers={2}" -f `
            $ServiceName,
            $queueSize,
            $workerCount
    )

    if (
        $queueSize -gt $ScaleUpThreshold -and
        $workerCount -lt $MaxWorkers
    ) {
        Set-WorkerScale `
            -ServiceName $ServiceName `
            -Replicas ($workerCount + 1)

        return
    }

    if (
        $queueSize -lt $ScaleDownThreshold -and
        $workerCount -gt $MinWorkers
    ) {
        Set-WorkerScale `
            -ServiceName $ServiceName `
            -Replicas ($workerCount - 1)

        return
    }

    Write-Host "No scaling required for $ServiceName."
}

Update-WorkerScale `
    -ServiceName 'worker-1' `
    -QueueName 'jobs.normal'

Update-WorkerScale `
    -ServiceName 'worker-2' `
    -QueueName 'jobs.priority'