<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Util\EngineHelper;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class Settings extends Command
{
    public function __construct(
        private IUserManager $userManager,
        private IUserConfig $userConfig,
        private EngineHelper $engineHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('x2mail:settings')
            ->setDescription('Set manual mail credentials when SSO is unavailable')
            ->addArgument(
                'uid',
                InputArgument::REQUIRED,
                'User ID used to login'
            )
            ->addArgument(
                'user',
                InputArgument::REQUIRED,
                'The login username (email address)'
            )
            ->addArgument(
                'pass',
                InputArgument::OPTIONAL,
                'The login passphrase'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = $input->getArgument('uid');
        if (!$this->userManager->userExists($uid)) {
            $output->writeln('<error>The user "' . $uid . '" does not exist.</error>');
            return 1;
        }

        $sEmail = $input->getArgument('user');
        $this->userConfig->setValueString($uid, 'x2mail', 'email', $sEmail);

        $sPass = $input->getArgument('pass');
        if (empty($sPass)) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $sPass = $helper->ask($input, $output, $question);
        }
        if (!\is_string($sPass) || $sPass === '') {
            $output->writeln('<error>A non-empty password is required.</error>');
            return 1;
        }

        $encoded = $sEmail && $sPass ? $this->engineHelper->encodePassword($sPass, \md5($sEmail)) : '';
        $this->userConfig->deleteUserConfig($uid, 'x2mail', 'passphrase');
        $this->userConfig->setValueString(
            $uid,
            'x2mail',
            'passphrase',
            $encoded,
            false,
            IUserConfig::FLAG_SENSITIVE | IUserConfig::FLAG_INTERNAL,
        );

        return 0;
    }
}
