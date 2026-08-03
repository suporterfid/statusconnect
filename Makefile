.PHONY: up down bootstrap composer artisan npm test e2e release deploy shell help

STC_SH := ./scripts/stc.sh

up down bootstrap composer artisan npm test e2e release deploy shell help:
	@bash $(STC_SH) $@ $(filter-out $@,$(MAKECMDGOALS))

%:
	@:
