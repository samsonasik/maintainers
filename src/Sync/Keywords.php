<?php
/**
 * @see       https://github.com/zendframework/maintainers for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/maintainers/blob/master/LICENSE.md New BSD License
 */

namespace ZF\Maintainer\Sync;

use Github\Client;
use Github\HttpClient\Message\ResponseMediator;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Keywords extends Command
{
    protected function configure()
    {
        $this
            ->setDescription('Synchronize composer.json keywords with GitHub topics')
            ->setHelp(
                'Update all GitHub topics to match keywords provided in composer.json file of the repository.'
            )
            ->addArgument(
                'token',
                InputArgument::REQUIRED,
                'GitHub access token'
            )
            ->addOption(
                'org',
                'o',
                InputOption::VALUE_REQUIRED,
                'Organization to update GitHub topics'
            )
            ->addOption(
                'repo',
                'r',
                InputOption::VALUE_REQUIRED,
                'Repository pattern to synchronize GitHub topics;'
                . ' * can be used to match any repository name or part of the name'
            )
            ->addOption('dry-run', null, null, 'Dry run to see what is going to be done.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $org = $input->getOption('org');
        if (! preg_match('/^[a-z0-9-]+$/i', $org)) {
            throw new InvalidArgumentException(
                'Invalid organization name, can contain only letter, numbers and dash'
            );
        }

        $repo = $input->getOption('repo');
        if (! preg_match('/^[a-z0-9*-]+$/i', $repo)) {
            throw new InvalidArgumentException(
                'Invalid repository pattern, can contain only letters, numbers, dash and *'
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $org = $input->getOption('org');
        $repo = $input->getOption('repo');
        $dryRun = $input->getOption('dry-run');

        $client = new Client();
        $client->authenticate($token, null, $client::AUTH_URL_TOKEN);
        $httpClient = $client->getHttpClient();

        $repos = $this->getRepos($client, $org, $repo);

        $output->writeln(sprintf('Found <info>%d</info> matching repositories:', count($repos)));

        foreach ($repos as $name => $repo) {
            $output->write(sprintf('%s: ', $name));
            if (! $repo['keywords']) {
                $output->writeln('<error>ERROR: Missing keywords in composer.json</error>');
                continue;
            }

            $toRemove = array_diff($repo['topics'], $repo['keywords']);
            foreach ($toRemove as $tag) {
                $output->write('<error>' . $tag . '</error> ');
            }

            $toAdd = array_diff($repo['keywords'], $repo['topics']);
            foreach ($toAdd as $tag) {
                $output->write('<question>' . $tag . '</question> ');
            }

            if (! $toRemove && ! $toAdd) {
                $output->write('<info>OK</info>');
            }

            $output->writeln('');

            if (! $dryRun) {
                $httpClient->put(
                    sprintf('/repos/%s/%s/topics', $org, $name),
                    ['Accept' => 'application/vnd.github.mercy-preview+json'],
                    json_encode(['names' => $repo['keywords']])
                );
            }
        }
    }

    private function getRepos(Client $client, $org, $repo)
    {
        $httpClient = $client->getHttpClient();

        $repos = [];

        $repoPreg = '/^' . str_replace('\*', '.*', preg_quote($repo, '\\')) . '$/';
        $repoSearchPhrase = str_replace('*', '', $repo);
        $page = 0;
        $processed = 0;

        do {
            $response = ResponseMediator::getContent(
                $httpClient->get('/search/repositories?' . http_build_query([
                    'q' => sprintf('%s in:name user:%s fork:true', $repoSearchPhrase, $org),
                    'order' => 'asc',
                    'page' => ++$page,
                ]), ['Accept' => 'application/vnd.github.mercy-preview+json'])
            );

            $totalCount = $response['total_count'];
            $processed += count($response['items']);

            foreach ($response['items'] as $item) {
                if (! preg_match($repoPreg, $item['name'])) {
                    continue;
                }

                $url = sprintf(
                    'https://raw.githubusercontent.com/%s/master/composer.json',
                    $item['full_name']
                );
                $json = json_decode(file_get_contents($url), true);

                $repos[$item['name']] = [
                    'topics' => $item['topics'],
                    'keywords' => isset($json['keywords']) ? $json['keywords'] : [],
                ];
            }
        } while ($processed < $totalCount);

        ksort($repos);

        return $repos;
    }
}
