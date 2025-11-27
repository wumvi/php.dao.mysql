db:
	docker compose up php-dao-mysql-replica1 php-dao-mysql-replica2 php-dao-mysql-master
exec:
	docker compose run --rm php-dao-cli sh