<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:remove', description: 'Remove a mail address from the server')]
final class AddressRemoveCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $gateway = $this->context()->gateway();

        return $this->mutateAddress(
            $output,
            $email,
            'delete',
            static function (string $email) use ($gateway): void {
                $gateway->deleteAddress($email);
            },
            'Mail address <info>%s</info> deleted.',
            'Mail address <info>%s</info> would be deleted (dry-run).',
        );
    }
}
